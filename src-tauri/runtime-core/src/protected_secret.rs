use std::{
    fs::{self, OpenOptions},
    io::{self, Write},
    path::{Path, PathBuf},
};

use base64::{engine::general_purpose::STANDARD, Engine as _};
use rand::RngCore;
#[cfg(windows)]
use zeroize::Zeroize;
use zeroize::Zeroizing;

const MAX_PROTECTED_FILE_BYTES: u64 = 128 * 1024;
const MAX_SECRET_BYTES: usize = 64 * 1024;

pub struct ProtectedSecret(Zeroizing<String>);

impl ProtectedSecret {
    pub fn expose(&self) -> &str {
        self.0.as_str()
    }
}

impl std::fmt::Debug for ProtectedSecret {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter.write_str("ProtectedSecret([REDACTED])")
    }
}

pub fn read_protected_secret(path: &Path) -> io::Result<ProtectedSecret> {
    ensure_private_regular_file(path)?;
    let metadata = fs::metadata(path)?;
    if metadata.len() == 0 || metadata.len() > MAX_PROTECTED_FILE_BYTES {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "protected secret has an invalid size",
        ));
    }

    let stored = Zeroizing::new(fs::read_to_string(path)?);
    let plaintext = unprotect(stored.trim())?;
    if plaintext.is_empty() || plaintext.len() > MAX_SECRET_BYTES || plaintext.contains('\0') {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "protected secret payload is invalid",
        ));
    }

    Ok(ProtectedSecret(Zeroizing::new(plaintext)))
}

pub fn write_new_protected_secret(path: &Path, secret: &str) -> io::Result<()> {
    if secret.is_empty() || secret.len() > MAX_SECRET_BYTES || secret.contains('\0') {
        return Err(io::Error::new(
            io::ErrorKind::InvalidInput,
            "secret payload is invalid",
        ));
    }

    let parent = path.parent().ok_or_else(|| {
        io::Error::new(
            io::ErrorKind::InvalidInput,
            "protected secret path has no parent",
        )
    })?;
    fs::create_dir_all(parent)?;
    if path.exists() {
        return Err(io::Error::new(
            io::ErrorKind::AlreadyExists,
            "protected secret already exists",
        ));
    }

    let mut stored = Zeroizing::new(protect(secret)?);
    stored.push('\n');
    let temporary = temporary_path_for(path);
    let mut options = OpenOptions::new();
    options.write(true).create_new(true);

    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }

    let result = (|| {
        let mut file = options.open(&temporary)?;
        file.write_all(stored.as_bytes())?;
        file.sync_all()?;
        drop(file);
        fs::hard_link(&temporary, path)?;
        fs::remove_file(&temporary)?;
        Ok(())
    })();

    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}

fn ensure_private_regular_file(path: &Path) -> io::Result<()> {
    let metadata = fs::symlink_metadata(path)?;
    let file_type = metadata.file_type();
    if file_type.is_symlink() || !file_type.is_file() {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "protected secret path is not a regular file",
        ));
    }

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        if metadata.permissions().mode() & 0o077 != 0 {
            return Err(io::Error::new(
                io::ErrorKind::PermissionDenied,
                "protected secret permissions are not user-only",
            ));
        }
    }

    Ok(())
}

fn temporary_path_for(path: &Path) -> PathBuf {
    let mut suffix = [0_u8; 8];
    rand::rng().fill_bytes(&mut suffix);
    let suffix = suffix
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<String>();
    path.with_extension(format!("tmp-{suffix}"))
}

#[cfg(not(windows))]
fn protect(secret: &str) -> io::Result<String> {
    Ok(format!("plain-v1:{}", STANDARD.encode(secret)))
}

#[cfg(not(windows))]
fn unprotect(stored: &str) -> io::Result<String> {
    let encoded = stored.strip_prefix("plain-v1:").ok_or_else(|| {
        io::Error::new(
            io::ErrorKind::InvalidData,
            "invalid protected secret prefix",
        )
    })?;
    let bytes = Zeroizing::new(
        STANDARD
            .decode(encoded)
            .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error))?,
    );
    String::from_utf8(bytes.to_vec())
        .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error))
}

#[cfg(windows)]
fn protect(secret: &str) -> io::Result<String> {
    use std::ptr::null;

    use windows_sys::Win32::{
        Foundation::LocalFree,
        Security::Cryptography::{CryptProtectData, CRYPTPROTECT_UI_FORBIDDEN, CRYPT_INTEGER_BLOB},
    };

    let length = u32::try_from(secret.len())
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "secret is too large"))?;
    let input = CRYPT_INTEGER_BLOB {
        cbData: length,
        pbData: secret.as_ptr().cast_mut(),
    };
    let mut output = CRYPT_INTEGER_BLOB::default();
    let protected = unsafe {
        CryptProtectData(
            &input,
            null(),
            null(),
            null(),
            null(),
            CRYPTPROTECT_UI_FORBIDDEN,
            &mut output,
        )
    };
    if protected == 0 {
        return Err(io::Error::last_os_error());
    }
    if output.pbData.is_null() || output.cbData == 0 {
        if !output.pbData.is_null() {
            unsafe { LocalFree(output.pbData.cast()) };
        }
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "DPAPI returned an empty protected secret",
        ));
    }

    let encoded = unsafe {
        let bytes = std::slice::from_raw_parts(output.pbData, output.cbData as usize);
        let encoded = STANDARD.encode(bytes);
        LocalFree(output.pbData.cast());
        encoded
    };

    Ok(format!("dpapi-v1:{encoded}"))
}

#[cfg(windows)]
fn unprotect(stored: &str) -> io::Result<String> {
    use std::ptr::{null, null_mut};

    use windows_sys::Win32::{
        Foundation::LocalFree,
        Security::Cryptography::{
            CryptUnprotectData, CRYPTPROTECT_UI_FORBIDDEN, CRYPT_INTEGER_BLOB,
        },
    };

    let encoded = stored.strip_prefix("dpapi-v1:").ok_or_else(|| {
        io::Error::new(
            io::ErrorKind::InvalidData,
            "invalid protected secret prefix",
        )
    })?;
    let mut ciphertext = Zeroizing::new(
        STANDARD
            .decode(encoded)
            .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error))?,
    );
    let length = u32::try_from(ciphertext.len())
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "secret is too large"))?;
    let input = CRYPT_INTEGER_BLOB {
        cbData: length,
        pbData: ciphertext.as_mut_ptr(),
    };
    let mut output = CRYPT_INTEGER_BLOB::default();
    let unprotected = unsafe {
        CryptUnprotectData(
            &input,
            null_mut(),
            null(),
            null(),
            null(),
            CRYPTPROTECT_UI_FORBIDDEN,
            &mut output,
        )
    };
    ciphertext.zeroize();
    if unprotected == 0 {
        return Err(io::Error::last_os_error());
    }
    if output.pbData.is_null() || output.cbData == 0 {
        if !output.pbData.is_null() {
            unsafe { LocalFree(output.pbData.cast()) };
        }
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "DPAPI returned an empty protected secret",
        ));
    }

    let plaintext = unsafe {
        let bytes = std::slice::from_raw_parts(output.pbData, output.cbData as usize);
        let result = String::from_utf8(bytes.to_vec())
            .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error));
        std::ptr::write_bytes(output.pbData, 0, output.cbData as usize);
        LocalFree(output.pbData.cast());
        result
    }?;

    Ok(plaintext)
}

#[cfg(test)]
mod tests {
    use super::*;
    use uuid::Uuid;

    fn temporary_directory() -> PathBuf {
        let path = std::env::temp_dir().join(format!("medismart-secret-{}", Uuid::new_v4()));
        fs::create_dir_all(&path).unwrap();
        path
    }

    #[test]
    fn protected_secret_round_trip_does_not_store_plaintext() {
        let directory = temporary_directory();
        let path = directory.join("cloudflared.token");
        let secret = "connector-token-that-must-not-appear";

        write_new_protected_secret(&path, secret).unwrap();
        let stored = fs::read_to_string(&path).unwrap();
        let loaded = read_protected_secret(&path).unwrap();

        assert!(!stored.contains(secret));
        assert_eq!(loaded.expose(), secret);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn protected_secret_is_create_only() {
        let directory = temporary_directory();
        let path = directory.join("cloudflared.token");
        write_new_protected_secret(&path, "first-secret").unwrap();

        let error = write_new_protected_secret(&path, "replacement-secret").unwrap_err();

        assert_eq!(error.kind(), io::ErrorKind::AlreadyExists);
        assert_eq!(
            read_protected_secret(&path).unwrap().expose(),
            "first-secret"
        );
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn protected_secret_rejects_symlinks_and_broad_permissions() {
        use std::os::unix::fs::{symlink, PermissionsExt};

        let directory = temporary_directory();
        let target = directory.join("target");
        let link = directory.join("link");
        write_new_protected_secret(&target, "protected-secret").unwrap();
        symlink(&target, &link).unwrap();
        assert_eq!(
            read_protected_secret(&link).unwrap_err().kind(),
            io::ErrorKind::InvalidData
        );

        fs::set_permissions(&target, fs::Permissions::from_mode(0o644)).unwrap();
        assert_eq!(
            read_protected_secret(&target).unwrap_err().kind(),
            io::ErrorKind::PermissionDenied
        );
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    fn write_private(path: &Path, contents: &[u8]) {
        use std::os::unix::fs::PermissionsExt;
        fs::write(path, contents).unwrap();
        fs::set_permissions(path, fs::Permissions::from_mode(0o600)).unwrap();
    }

    #[test]
    fn debug_output_never_reveals_the_secret() {
        let secret = ProtectedSecret(Zeroizing::new("super-secret-value".to_owned()));

        let rendered = format!("{secret:?}");

        assert_eq!(rendered, "ProtectedSecret([REDACTED])");
        assert!(!rendered.contains("super-secret-value"));
    }

    #[test]
    fn invalid_secret_payloads_are_rejected_before_touching_disk() {
        let directory = temporary_directory();
        let path = directory.join("nested").join("secret.token");
        let oversized = "x".repeat(MAX_SECRET_BYTES + 1);

        for secret in ["", "contains\0nul", oversized.as_str()] {
            let error = write_new_protected_secret(&path, secret).unwrap_err();
            assert_eq!(error.kind(), io::ErrorKind::InvalidInput);
        }
        assert!(!directory.join("nested").exists());
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn maximum_sized_secret_round_trips() {
        let directory = temporary_directory();
        let path = directory.join("max.token");
        let secret = "a".repeat(MAX_SECRET_BYTES);

        write_new_protected_secret(&path, &secret).unwrap();

        assert_eq!(read_protected_secret(&path).unwrap().expose(), secret);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn unicode_secret_round_trips() {
        let directory = temporary_directory();
        let path = directory.join("unicode.token");
        let secret = "clé-secrète-日本語-🔐";

        write_new_protected_secret(&path, secret).unwrap();

        assert_eq!(read_protected_secret(&path).unwrap().expose(), secret);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn writer_creates_missing_parent_directories_and_leaves_no_temporary_file() {
        let directory = temporary_directory();
        let path = directory.join("a").join("b").join("secret.token");

        write_new_protected_secret(&path, "value").unwrap();

        let names = fs::read_dir(path.parent().unwrap())
            .unwrap()
            .map(|entry| entry.unwrap().file_name().into_string().unwrap())
            .collect::<Vec<_>>();
        assert_eq!(names, vec!["secret.token"]);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn path_without_parent_is_rejected() {
        let error = write_new_protected_secret(Path::new("/"), "value").unwrap_err();

        assert_eq!(error.kind(), io::ErrorKind::InvalidInput);
    }

    #[test]
    fn missing_secret_file_reports_not_found() {
        let directory = temporary_directory();

        let error = read_protected_secret(&directory.join("absent")).unwrap_err();

        assert_eq!(error.kind(), io::ErrorKind::NotFound);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn directory_is_not_accepted_as_secret_file() {
        let directory = temporary_directory();
        let path = directory.join("dir.token");
        fs::create_dir(&path).unwrap();

        let error = read_protected_secret(&path).unwrap_err();

        assert_eq!(error.kind(), io::ErrorKind::InvalidData);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn written_secret_is_user_only() {
        use std::os::unix::fs::PermissionsExt;

        let directory = temporary_directory();
        let path = directory.join("secret.token");
        write_new_protected_secret(&path, "value").unwrap();

        let mode = fs::metadata(&path).unwrap().permissions().mode() & 0o777;

        assert_eq!(mode, 0o600);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn group_readable_secret_is_rejected() {
        use std::os::unix::fs::PermissionsExt;

        let directory = temporary_directory();
        let path = directory.join("secret.token");
        write_new_protected_secret(&path, "value").unwrap();
        fs::set_permissions(&path, fs::Permissions::from_mode(0o640)).unwrap();

        let error = read_protected_secret(&path).unwrap_err();

        assert_eq!(error.kind(), io::ErrorKind::PermissionDenied);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn empty_and_oversized_stored_files_are_rejected_by_size() {
        let directory = temporary_directory();
        let empty = directory.join("empty.token");
        let huge = directory.join("huge.token");
        write_private(&empty, b"");
        write_private(&huge, &vec![b'a'; MAX_PROTECTED_FILE_BYTES as usize + 1]);

        assert_eq!(
            read_protected_secret(&empty).unwrap_err().kind(),
            io::ErrorKind::InvalidData
        );
        assert_eq!(
            read_protected_secret(&huge).unwrap_err().kind(),
            io::ErrorKind::InvalidData
        );
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn stored_payload_with_wrong_prefix_or_encoding_is_rejected() {
        let directory = temporary_directory();
        let path = directory.join("secret.token");
        let cases: Vec<String> = vec![
            STANDARD.encode("value"),
            format!("plain-v2:{}", STANDARD.encode("value")),
            "plain-v1:###".to_owned(),
            format!("plain-v1:{}", STANDARD.encode([0xff_u8, 0xfe, 0xfd])),
            "plain-v1:".to_owned(),
            format!("plain-v1:{}", STANDARD.encode("a\0b")),
        ];

        for case in cases {
            write_private(&path, case.as_bytes());
            let error = read_protected_secret(&path).unwrap_err();
            assert_eq!(error.kind(), io::ErrorKind::InvalidData, "case {case:?}");
        }
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn stored_payload_tolerates_surrounding_whitespace() {
        let directory = temporary_directory();
        let path = directory.join("secret.token");
        write_private(
            &path,
            format!("\n  plain-v1:{}  \r\n", STANDARD.encode("value")).as_bytes(),
        );

        assert_eq!(read_protected_secret(&path).unwrap().expose(), "value");
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(not(windows))]
    #[test]
    fn non_windows_protection_is_a_versioned_base64_envelope() {
        let stored = protect("abc").unwrap();

        assert_eq!(stored, "plain-v1:YWJj");
        assert_eq!(unprotect(&stored).unwrap(), "abc");
    }
}
