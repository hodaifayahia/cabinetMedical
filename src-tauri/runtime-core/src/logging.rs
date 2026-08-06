use std::{
    fs::{self, File, OpenOptions},
    io::{self, Write},
    path::{Path, PathBuf},
    sync::Mutex,
};

use regex::{Captures, Regex, RegexBuilder};
use zeroize::Zeroizing;

const MAX_LOG_BYTES: u64 = 5 * 1024 * 1024;
const MAX_MESSAGE_BYTES: usize = 8 * 1024;

pub struct RuntimeLogger {
    file: Mutex<File>,
    redactor: Redactor,
}

impl RuntimeLogger {
    pub fn open(
        log_directory: &Path,
        known_secrets: &[String],
        private_paths: &[PathBuf],
    ) -> io::Result<Self> {
        Self::open_named(
            log_directory,
            "desktop-supervisor.log",
            known_secrets,
            private_paths,
        )
    }

    pub fn open_named(
        log_directory: &Path,
        file_name: &str,
        known_secrets: &[String],
        private_paths: &[PathBuf],
    ) -> io::Result<Self> {
        let known_secrets = known_secrets.iter().map(String::as_str).collect::<Vec<_>>();
        Self::open_named_with_secret_refs(log_directory, file_name, &known_secrets, private_paths)
    }

    pub fn open_named_with_secret_refs(
        log_directory: &Path,
        file_name: &str,
        known_secrets: &[&str],
        private_paths: &[PathBuf],
    ) -> io::Result<Self> {
        if !valid_log_file_name(file_name) {
            return Err(io::Error::new(
                io::ErrorKind::InvalidInput,
                "invalid runtime log file name",
            ));
        }

        fs::create_dir_all(log_directory)?;
        let path = log_directory.join(file_name);
        match fs::symlink_metadata(&path) {
            Ok(metadata) => {
                let file_type = metadata.file_type();
                if file_type.is_symlink() || !file_type.is_file() {
                    return Err(io::Error::new(
                        io::ErrorKind::InvalidData,
                        "desktop log path is not a regular file",
                    ));
                }
            }
            Err(error) if error.kind() == io::ErrorKind::NotFound => {}
            Err(error) => return Err(error),
        }
        rotate_if_needed(&path)?;

        let file = OpenOptions::new().create(true).append(true).open(path)?;

        Ok(Self {
            file: Mutex::new(file),
            redactor: Redactor::new(known_secrets, private_paths),
        })
    }

    pub fn info(&self, message: &str) {
        self.write("INFO", message);
    }

    pub fn warn(&self, message: &str) {
        self.write("WARN", message);
    }

    pub fn error(&self, message: &str) {
        self.write("ERROR", message);
    }

    pub fn child_output(&self, stream: &str, message: &str) {
        if stream.starts_with("TUNNEL-") {
            if !message.trim().is_empty() {
                self.write(
                    stream,
                    "[cloudflared process output omitted; see stable tunnel state]",
                );
            }
            return;
        }
        if stream.starts_with("QUEUE-") {
            if !message.trim().is_empty() {
                self.write(
                    stream,
                    "[queue worker process output omitted; see stable queue worker state]",
                );
            }
            return;
        }
        if stream.starts_with("SCHEDULER-") {
            if !message.trim().is_empty() {
                self.write(
                    stream,
                    "[scheduler process output omitted; see stable scheduler state]",
                );
            }
            return;
        }
        self.write(stream, message);
    }

    fn write(&self, level: &str, message: &str) {
        let message = if message.len() > MAX_MESSAGE_BYTES {
            "[oversized process message omitted]".to_owned()
        } else {
            self.redactor.redact(message)
        };
        let timestamp = crate::diagnostics::now_unix_ms();

        if let Ok(mut file) = self.file.lock() {
            let _ = writeln!(file, "[{timestamp}] [{level}] {}", message.trim_end());
            let _ = file.flush();
        }
    }
}

fn valid_log_file_name(file_name: &str) -> bool {
    file_name.ends_with(".log")
        && file_name.len() <= 64
        && file_name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.'))
}

fn rotate_if_needed(path: &Path) -> io::Result<()> {
    let Ok(metadata) = fs::metadata(path) else {
        return Ok(());
    };
    if metadata.len() < MAX_LOG_BYTES {
        return Ok(());
    }

    let rotated = path.with_extension("log.1");
    if rotated.exists() {
        fs::remove_file(&rotated)?;
    }
    fs::rename(path, rotated)
}

struct Redactor {
    credentials: Regex,
    query_secrets: Regex,
    bearer: Regex,
    upload_tokens: Regex,
    known_values: Vec<Zeroizing<String>>,
    private_paths: Vec<String>,
}

impl Redactor {
    fn new(known_secrets: &[&str], private_paths: &[PathBuf]) -> Self {
        let case_insensitive_paths = cfg!(target_os = "windows");
        let mut paths = private_paths
            .iter()
            .map(|path| path.to_string_lossy().to_string())
            .filter(|path| !path.is_empty())
            .collect::<Vec<_>>();
        paths.sort_by_key(|path| std::cmp::Reverse(path.len()));

        Self {
            credentials: Regex::new(
                r"(?i)(authorization|proxy-authorization|x-medismart-health-key|client_secret|refresh_token|access_token|password|token|secret|app_key)(\s*[:=]\s*)([^\s&,;]+)",
            )
            .expect("credential redaction regex is valid"),
            query_secrets: Regex::new(
                r"(?i)([?&](?:token|key|secret|code|state|password)=)[^&\s]+",
            )
            .expect("query redaction regex is valid"),
            bearer: Regex::new(r"(?i)\bbearer\s+[A-Za-z0-9._~+/=-]+")
                .expect("bearer redaction regex is valid"),
            upload_tokens: Regex::new(r"(/upload/)[A-Za-z0-9_-]{16,}")
                .expect("upload token redaction regex is valid"),
            known_values: known_secrets
                .iter()
                .filter(|value| value.len() >= 8)
                .map(|value| Zeroizing::new((*value).to_owned()))
                .collect(),
            private_paths: paths
                .into_iter()
                .map(|path| {
                    if case_insensitive_paths {
                        path.to_lowercase()
                    } else {
                        path
                    }
                })
                .collect(),
        }
    }

    fn redact(&self, input: &str) -> String {
        let mut output = input.to_owned();

        for secret in &self.known_values {
            output = output.replace(secret.as_str(), "[REDACTED]");
        }

        output = self
            .bearer
            .replace_all(&output, "Bearer [REDACTED]")
            .into_owned();
        output = self
            .credentials
            .replace_all(&output, |captures: &Captures<'_>| {
                format!("{}{}[REDACTED]", &captures[1], &captures[2])
            })
            .into_owned();
        output = self
            .query_secrets
            .replace_all(&output, "$1[REDACTED]")
            .into_owned();
        output = self
            .upload_tokens
            .replace_all(&output, "$1[REDACTED]")
            .into_owned();

        for private_path in &self.private_paths {
            if cfg!(target_os = "windows") {
                output = replace_case_insensitive(&output, private_path, "[PRIVATE_PATH]");
            } else {
                output = output.replace(private_path, "[PRIVATE_PATH]");
            }
        }

        output
    }
}

fn replace_case_insensitive(input: &str, needle: &str, replacement: &str) -> String {
    if needle.is_empty() {
        return input.to_owned();
    }

    RegexBuilder::new(&regex::escape(needle))
        .case_insensitive(true)
        .build()
        .expect("escaped path regex is valid")
        .replace_all(input, replacement)
        .into_owned()
}

#[cfg(test)]
mod tests {
    use std::{fs, path::PathBuf};

    use uuid::Uuid;

    use super::*;

    #[test]
    fn child_log_redacts_credentials_tokens_and_private_paths() {
        let directory = std::env::temp_dir().join(format!("medismart-log-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let logger = RuntimeLogger::open(
            &directory,
            &["health-secret-123".to_owned()],
            &[PathBuf::from("/home/clinic/MediSmart")],
        )
        .unwrap();

        logger.child_output(
            "PHP",
            "GET /upload/abcdefghijklmnopqrst?token=raw-token Authorization: Bearer abc.def health-secret-123 /home/clinic/MediSmart/storage",
        );
        drop(logger);

        let output = fs::read_to_string(directory.join("desktop-supervisor.log")).unwrap();
        assert!(!output.contains("abcdefghijklmnopqrst"));
        assert!(!output.contains("raw-token"));
        assert!(!output.contains("abc.def"));
        assert!(!output.contains("health-secret-123"));
        assert!(!output.contains("/home/clinic"));
        assert!(output.contains("[REDACTED]"));
        assert!(output.contains("[PRIVATE_PATH]"));

        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn cloudflared_provider_output_is_never_persisted_verbatim() {
        let directory = std::env::temp_dir().join(format!("medismart-log-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let logger =
            RuntimeLogger::open_named(&directory, "cloudflared-supervisor.log", &[], &[]).unwrap();

        logger.child_output(
            "TUNNEL-ERR",
            r#"Updated to new configuration {"headers":{"X-Clinic":"unclassified-sensitive-value"}}"#,
        );
        drop(logger);

        let output = fs::read_to_string(directory.join("cloudflared-supervisor.log")).unwrap();
        assert!(!output.contains("unclassified-sensitive-value"));
        assert!(output.contains("cloudflared process output omitted"));
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn queue_worker_output_is_never_persisted_verbatim() {
        let directory = std::env::temp_dir().join(format!("medismart-log-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let logger =
            RuntimeLogger::open_named(&directory, "queue-worker-supervisor.log", &[], &[]).unwrap();

        logger.child_output(
            "QUEUE-ERR",
            "failed job contained unclassified-sensitive-patient-value",
        );
        drop(logger);

        let output = fs::read_to_string(directory.join("queue-worker-supervisor.log")).unwrap();
        assert!(!output.contains("unclassified-sensitive-patient-value"));
        assert!(output.contains("queue worker process output omitted"));
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn log_symlinks_are_rejected() {
        use std::os::unix::fs::symlink;

        let directory = std::env::temp_dir().join(format!("medismart-log-link-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let external = directory.join("external.log");
        fs::write(&external, b"do not append").unwrap();
        symlink(&external, directory.join("desktop-supervisor.log")).unwrap();

        let error = RuntimeLogger::open(&directory, &[], &[]).err().unwrap();

        assert_eq!(error.kind(), io::ErrorKind::InvalidData);
        assert_eq!(fs::read(&external).unwrap(), b"do not append");
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn scheduler_output_is_never_persisted_verbatim() {
        let directory = std::env::temp_dir().join(format!("medismart-log-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        let logger =
            RuntimeLogger::open_named(&directory, "scheduler-supervisor.log", &[], &[]).unwrap();

        logger.child_output(
            "SCHEDULER-ERR",
            "scheduled command contained unclassified-sensitive-patient-value",
        );
        drop(logger);

        let output = fs::read_to_string(directory.join("scheduler-supervisor.log")).unwrap();
        assert!(!output.contains("unclassified-sensitive-patient-value"));
        assert!(output.contains("scheduler process output omitted"));
        fs::remove_dir_all(directory).unwrap();
    }
}
