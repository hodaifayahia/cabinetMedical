//! Tie long-running child processes to the lifetime of the desktop app.
//!
//! On Windows a child process outlives its parent. When Drclick is closed
//! normally it stops PHP, the queue worker, the scheduler and cloudflared
//! itself, but when it is force-closed (Task Manager, a crash, the installer
//! stopping it before an update) they kept running and kept the bundled PHP
//! DLLs locked, so the next installation could not replace them.
//!
//! Every such child is placed in one Windows job object created with
//! `JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`. The app holds the only handle to
//! that job; however the app ends, Windows closes the handle and ends every
//! process still in the job. The app itself is never placed in the job, so
//! anything else it starts (an update installer, a document opened in Word)
//! is unaffected.

use std::process::Child;

/// Make `child` end when the desktop app ends, however it ends.
///
/// Returns a short reason when Windows refused; the caller logs it and keeps
/// the child, which then behaves exactly as before.
#[cfg(windows)]
pub(crate) fn end_with_app(child: &Child) -> Result<(), &'static str> {
    use std::{os::windows::io::AsRawHandle, sync::OnceLock};
    use windows_sys::Win32::System::JobObjects::AssignProcessToJobObject;

    static JOB: OnceLock<Option<usize>> = OnceLock::new();

    let job = JOB
        .get_or_init(|| create_kill_on_close_job().map(|handle| handle as usize))
        .ok_or("child_job_unavailable")?;

    // SAFETY: the job handle is valid for the life of the process (it is never
    // closed) and the child handle is owned by `child`, which outlives the call.
    let assigned = unsafe { AssignProcessToJobObject(job as _, child.as_raw_handle() as _) };
    if assigned == 0 {
        return Err("child_job_assign_failed");
    }

    Ok(())
}

#[cfg(not(windows))]
pub(crate) fn end_with_app(_child: &Child) -> Result<(), &'static str> {
    Ok(())
}

#[cfg(windows)]
fn create_kill_on_close_job() -> Option<windows_sys::Win32::Foundation::HANDLE> {
    use windows_sys::Win32::{
        Foundation::CloseHandle,
        System::JobObjects::{
            CreateJobObjectW, JobObjectExtendedLimitInformation, SetInformationJobObject,
            JOBOBJECT_EXTENDED_LIMIT_INFORMATION, JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE,
        },
    };

    // SAFETY: plain Win32 calls on a job handle this function owns; the
    // information structure is fully initialised and sized exactly.
    unsafe {
        let job = CreateJobObjectW(std::ptr::null(), std::ptr::null());
        if job.is_null() {
            return None;
        }

        let mut limits = JOBOBJECT_EXTENDED_LIMIT_INFORMATION::default();
        limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
        let configured = SetInformationJobObject(
            job,
            JobObjectExtendedLimitInformation,
            &limits as *const JOBOBJECT_EXTENDED_LIMIT_INFORMATION as *const _,
            std::mem::size_of::<JOBOBJECT_EXTENDED_LIMIT_INFORMATION>() as u32,
        );
        if configured == 0 {
            CloseHandle(job);
            return None;
        }

        Some(job)
    }
}

#[cfg(all(test, windows))]
mod tests {
    use std::{
        os::windows::io::AsRawHandle,
        process::{Command, Stdio},
    };

    use windows_sys::Win32::System::JobObjects::IsProcessInJob;

    use super::end_with_app;

    #[test]
    fn a_bound_child_is_placed_in_the_job() {
        let mut child = Command::new("cmd")
            .args(["/C", "ping -n 30 127.0.0.1 >NUL"])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .spawn()
            .expect("spawn a long-running child");

        let bound = end_with_app(&child);
        let mut in_job = 0;
        // SAFETY: the child handle is valid; a null job asks about any job.
        unsafe {
            IsProcessInJob(
                child.as_raw_handle() as _,
                std::ptr::null_mut(),
                &mut in_job,
            )
        };

        let _ = child.kill();
        let _ = child.wait();
        assert_eq!(bound, Ok(()));
        assert_ne!(in_job, 0, "the child must be in the job");
    }
}
