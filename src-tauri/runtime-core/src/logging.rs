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
            &[PathBuf::from("/home/clinic/Drclick")],
        )
        .unwrap();

        logger.child_output(
            "PHP",
            "GET /upload/abcdefghijklmnopqrst?token=raw-token Authorization: Bearer abc.def health-secret-123 /home/clinic/Drclick/storage",
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

    fn temporary_log_directory() -> PathBuf {
        let directory = std::env::temp_dir().join(format!("medismart-log-x-{}", Uuid::new_v4()));
        fs::create_dir_all(&directory).unwrap();
        directory
    }

    fn redactor(secrets: &[&str], paths: &[&str]) -> Redactor {
        Redactor::new(
            secrets,
            &paths.iter().map(PathBuf::from).collect::<Vec<_>>(),
        )
    }

    #[test]
    fn log_file_names_must_be_short_plain_dot_log_names() {
        assert!(valid_log_file_name("desktop-supervisor.log"));
        assert!(valid_log_file_name("a_b.c-d.log"));
        assert!(valid_log_file_name(&format!("{}.log", "a".repeat(60))));

        assert!(!valid_log_file_name(&format!("{}.log", "a".repeat(61))));
        assert!(!valid_log_file_name("desktop.txt"));
        assert!(!valid_log_file_name("../escape.log"));
        assert!(!valid_log_file_name("nested/file.log"));
        assert!(!valid_log_file_name("nested\\file.log"));
        assert!(!valid_log_file_name("space name.log"));
        assert!(!valid_log_file_name("unicodé.log"));
        assert!(!valid_log_file_name(""));
    }

    #[test]
    fn opening_with_invalid_file_name_fails_without_creating_directory() {
        let root = temporary_log_directory();
        let directory = root.join("logs");

        let error = RuntimeLogger::open_named(&directory, "../evil.log", &[], &[])
            .err()
            .unwrap();

        assert_eq!(error.kind(), io::ErrorKind::InvalidInput);
        assert!(!directory.exists());
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn opening_creates_missing_log_directory() {
        let root = temporary_log_directory();
        let directory = root.join("deep").join("logs");

        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();
        logger.info("hello");
        drop(logger);

        assert!(directory.join("desktop-supervisor.log").is_file());
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn directory_at_log_path_is_rejected() {
        let directory = temporary_log_directory();
        fs::create_dir(directory.join("desktop-supervisor.log")).unwrap();

        let error = RuntimeLogger::open(&directory, &[], &[]).err().unwrap();

        assert_eq!(error.kind(), io::ErrorKind::InvalidData);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn levels_are_written_with_timestamp_and_trailing_whitespace_trimmed() {
        let directory = temporary_log_directory();
        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();

        logger.info("first message   \n");
        logger.warn("second");
        logger.error("third");
        logger.child_output("PHP-OUT", "child line");
        drop(logger);

        let output = fs::read_to_string(directory.join("desktop-supervisor.log")).unwrap();
        let lines = output.lines().collect::<Vec<_>>();
        assert_eq!(lines.len(), 4);
        let expected = [
            "[INFO] first message",
            "[WARN] second",
            "[ERROR] third",
            "[PHP-OUT] child line",
        ];
        for (line, suffix) in lines.iter().zip(expected) {
            assert!(line.starts_with('['), "{line}");
            let timestamp = &line[1..line.find(']').unwrap()];
            assert!(timestamp.parse::<u128>().unwrap() > 0);
            assert!(line.ends_with(suffix), "{line} should end with {suffix}");
        }
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn reopening_appends_rather_than_truncating() {
        let directory = temporary_log_directory();
        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();
        logger.info("one");
        drop(logger);
        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();
        logger.info("two");
        drop(logger);

        let output = fs::read_to_string(directory.join("desktop-supervisor.log")).unwrap();
        assert!(output.contains("[INFO] one"));
        assert!(output.contains("[INFO] two"));
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn oversized_messages_are_replaced_with_a_placeholder() {
        let directory = temporary_log_directory();
        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();
        let exact = "e".repeat(MAX_MESSAGE_BYTES);
        let oversized = "o".repeat(MAX_MESSAGE_BYTES + 1);

        logger.info(&exact);
        logger.info(&oversized);
        drop(logger);

        let output = fs::read_to_string(directory.join("desktop-supervisor.log")).unwrap();
        assert!(output.contains(&exact));
        assert!(!output.contains(&"o".repeat(64)));
        assert!(output.contains("[oversized process message omitted]"));
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn blank_supervised_child_output_is_not_logged() {
        let directory = temporary_log_directory();
        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();

        logger.child_output("TUNNEL-OUT", "   ");
        logger.child_output("QUEUE-OUT", "\n");
        logger.child_output("SCHEDULER-OUT", "");
        drop(logger);

        let output = fs::read_to_string(directory.join("desktop-supervisor.log")).unwrap();
        assert!(output.is_empty());
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn stream_prefix_matching_is_case_sensitive_and_prefix_only() {
        let directory = temporary_log_directory();
        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();

        logger.child_output("tunnel-out", "lowercase-stream-visible");
        logger.child_output("PHP-TUNNEL-OUT", "embedded-stream-visible");
        drop(logger);

        let output = fs::read_to_string(directory.join("desktop-supervisor.log")).unwrap();
        assert!(output.contains("lowercase-stream-visible"));
        assert!(output.contains("embedded-stream-visible"));
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn small_logs_are_not_rotated() {
        let directory = temporary_log_directory();
        let path = directory.join("desktop-supervisor.log");
        fs::write(&path, b"existing\n").unwrap();

        rotate_if_needed(&path).unwrap();

        assert_eq!(fs::read(&path).unwrap(), b"existing\n");
        assert!(!directory.join("desktop-supervisor.log.1").exists());
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn rotation_of_missing_log_is_a_no_op() {
        let directory = temporary_log_directory();

        rotate_if_needed(&directory.join("absent.log")).unwrap();

        assert_eq!(fs::read_dir(&directory).unwrap().count(), 0);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn logs_at_the_size_limit_rotate_and_replace_previous_rotation() {
        let directory = temporary_log_directory();
        let path = directory.join("desktop-supervisor.log");
        let rotated = directory.join("desktop-supervisor.log.1");
        fs::write(&rotated, b"old rotation").unwrap();
        let file = File::create(&path).unwrap();
        file.set_len(MAX_LOG_BYTES).unwrap();
        drop(file);

        let logger = RuntimeLogger::open(&directory, &[], &[]).unwrap();
        logger.info("fresh");
        drop(logger);

        assert_eq!(fs::metadata(&rotated).unwrap().len(), MAX_LOG_BYTES);
        let fresh = fs::read_to_string(&path).unwrap();
        assert!(fresh.contains("[INFO] fresh"));
        assert!((fresh.len() as u64) < 1024);
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn logs_just_below_the_size_limit_are_kept() {
        let directory = temporary_log_directory();
        let path = directory.join("desktop-supervisor.log");
        let file = File::create(&path).unwrap();
        file.set_len(MAX_LOG_BYTES - 1).unwrap();
        drop(file);

        rotate_if_needed(&path).unwrap();

        assert_eq!(fs::metadata(&path).unwrap().len(), MAX_LOG_BYTES - 1);
        assert!(!directory.join("desktop-supervisor.log.1").exists());
        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn known_secrets_shorter_than_eight_bytes_are_not_used_for_redaction() {
        let redactor = redactor(&["short", "long-enough-secret"], &[]);

        let output = redactor.redact("short words and long-enough-secret here");

        assert_eq!(output, "short words and [REDACTED] here");
    }

    #[test]
    fn every_occurrence_of_a_known_secret_is_redacted() {
        let redactor = redactor(&["repeat-secret"], &[]);

        let output = redactor.redact("repeat-secret/repeat-secret:repeat-secret");

        assert!(!output.contains("repeat-secret"));
        assert_eq!(output.matches("[REDACTED]").count(), 3);
    }

    #[test]
    fn credential_assignments_are_redacted_case_insensitively() {
        let redactor = redactor(&[], &[]);

        for (input, expected) in [
            ("password=hunter2", "password=[REDACTED]"),
            ("PASSWORD : hunter2", "PASSWORD : [REDACTED]"),
            ("client_secret=abc,next", "client_secret=[REDACTED],next"),
            ("refresh_token: r1;rest", "refresh_token: [REDACTED];rest"),
            ("APP_KEY=base64:xyz", "APP_KEY=[REDACTED]"),
            (
                "x-medismart-health-key: k123",
                "x-medismart-health-key: [REDACTED]",
            ),
        ] {
            assert_eq!(redactor.redact(input), expected, "input {input}");
        }
    }

    #[test]
    fn query_string_secrets_are_redacted_but_other_parameters_kept() {
        let redactor = redactor(&[], &[]);

        let output = redactor.redact("GET /callback?code=c0de&page=2&state=s7ate&key=k3y HTTP/1.1");

        assert!(!output.contains("c0de"));
        assert!(!output.contains("s7ate"));
        assert!(!output.contains("k3y"));
        assert!(output.contains("page=2"));
        assert!(output.contains("HTTP/1.1"));
    }

    #[test]
    fn bearer_tokens_are_redacted_regardless_of_case() {
        let redactor = redactor(&[], &[]);

        let output = redactor.redact("sent bearer eyJhbGciOi.payload.sig~+/= ok");

        assert_eq!(output, "sent Bearer [REDACTED] ok");
    }

    #[test]
    fn only_long_upload_tokens_are_redacted() {
        let redactor = redactor(&[], &[]);

        assert_eq!(
            redactor.redact("/upload/ABCDEFGHIJKLMNOP/done"),
            "/upload/[REDACTED]/done"
        );
        assert_eq!(
            redactor.redact("/upload/short-id/done"),
            "/upload/short-id/done"
        );
    }

    #[test]
    fn longer_private_paths_are_replaced_before_their_prefixes() {
        let redactor = redactor(&[], &["/data", "/data/clinic/private", ""]);

        let output = redactor.redact("open /data/clinic/private/db.sqlite and /data/other");

        assert_eq!(
            output,
            "open [PRIVATE_PATH]/db.sqlite and [PRIVATE_PATH]/other"
        );
    }

    #[cfg(not(target_os = "windows"))]
    #[test]
    fn private_paths_are_case_sensitive_off_windows() {
        let redactor = redactor(&[], &["/Home/Clinic"]);

        assert_eq!(redactor.redact("/home/clinic/x"), "/home/clinic/x");
        assert_eq!(redactor.redact("/Home/Clinic/x"), "[PRIVATE_PATH]/x");
    }

    #[test]
    fn plain_text_without_sensitive_content_is_unchanged() {
        let redactor = redactor(&["known-secret-value"], &["/private/root"]);
        let input = "Laravel server started on http://127.0.0.1:8123 in 42ms";

        assert_eq!(redactor.redact(input), input);
    }

    #[test]
    fn case_insensitive_replacement_escapes_regex_metacharacters() {
        assert_eq!(
            replace_case_insensitive(
                r"C:\USERS\Clinic (1)\db and c:\users\clinic (1)\x",
                r"c:\users\clinic (1)",
                "[P]"
            ),
            r"[P]\db and [P]\x"
        );
        assert_eq!(replace_case_insensitive("a.b axb", "a.b", "[P]"), "[P] axb");
        assert_eq!(
            replace_case_insensitive("unchanged", "", "[P]"),
            "unchanged"
        );
    }
}
