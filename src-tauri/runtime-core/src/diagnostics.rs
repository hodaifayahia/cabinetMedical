use serde::{Deserialize, Serialize};

/// Deliberately small and path-free. This is safe to copy into a support
/// request; detailed process output stays in the locally stored filtered log.
#[derive(Clone, Debug, Deserialize, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum RuntimePhase {
    Starting,
    Healthy,
    Retrying,
    Failed,
    Stopping,
    Stopped,
}

#[derive(Clone, Debug, Deserialize, PartialEq, Eq, Serialize)]
pub struct RuntimeSnapshot {
    pub schema_version: u8,
    pub phase: RuntimePhase,
    pub local_port: Option<u16>,
    pub process_id: Option<u32>,
    pub retry_count: u8,
    pub last_error_code: Option<String>,
    pub updated_at_unix_ms: u128,
}

impl RuntimeSnapshot {
    pub fn starting(retry_count: u8) -> Self {
        Self {
            schema_version: 1,
            phase: if retry_count == 0 {
                RuntimePhase::Starting
            } else {
                RuntimePhase::Retrying
            },
            local_port: None,
            process_id: None,
            retry_count,
            last_error_code: None,
            updated_at_unix_ms: now_unix_ms(),
        }
    }

    pub fn touch(&mut self) {
        self.updated_at_unix_ms = now_unix_ms();
    }
}

pub(crate) fn now_unix_ms() -> u128 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn support_snapshot_has_no_path_or_secret_fields() {
        let mut snapshot = RuntimeSnapshot::starting(0);
        snapshot.phase = RuntimePhase::Failed;
        snapshot.last_error_code = Some("health_timeout".to_owned());

        let json = serde_json::to_string(&snapshot).unwrap();

        assert!(!json.contains("path"));
        assert!(!json.contains("token"));
        assert!(!json.contains("secret"));
        assert!(!json.contains("key"));
        assert!(json.contains("health_timeout"));
    }

    #[test]
    fn first_attempt_starts_and_later_attempts_retry() {
        let first = RuntimeSnapshot::starting(0);
        let retry = RuntimeSnapshot::starting(3);

        assert_eq!(first.phase, RuntimePhase::Starting);
        assert_eq!(retry.phase, RuntimePhase::Retrying);
        assert_eq!(retry.retry_count, 3);
        assert_eq!(first.schema_version, 1);
        assert_eq!(first.local_port, None);
        assert_eq!(first.process_id, None);
        assert_eq!(first.last_error_code, None);
        assert!(first.updated_at_unix_ms > 0);
    }

    #[test]
    fn touch_never_moves_the_timestamp_backwards() {
        let mut snapshot = RuntimeSnapshot::starting(0);
        snapshot.updated_at_unix_ms = 1;

        snapshot.touch();

        assert!(snapshot.updated_at_unix_ms > 1);
        assert!(snapshot.updated_at_unix_ms <= now_unix_ms());
    }

    #[test]
    fn phases_serialize_as_snake_case() {
        for (phase, name) in [
            (RuntimePhase::Starting, "starting"),
            (RuntimePhase::Healthy, "healthy"),
            (RuntimePhase::Retrying, "retrying"),
            (RuntimePhase::Failed, "failed"),
            (RuntimePhase::Stopping, "stopping"),
            (RuntimePhase::Stopped, "stopped"),
        ] {
            assert_eq!(
                serde_json::to_string(&phase).unwrap(),
                format!("\"{name}\"")
            );
        }
    }

    #[test]
    fn snapshot_round_trips_through_json() {
        let mut snapshot = RuntimeSnapshot::starting(2);
        snapshot.local_port = Some(43123);
        snapshot.process_id = Some(4242);
        snapshot.last_error_code = Some("laravel_exited".to_owned());

        let json = serde_json::to_string(&snapshot).unwrap();
        let decoded: RuntimeSnapshot = serde_json::from_str(&json).unwrap();

        assert_eq!(decoded, snapshot);
    }

    #[test]
    fn unknown_phase_is_rejected_when_reading_a_snapshot() {
        let json = r#"{"schema_version":1,"phase":"exploded","local_port":null,"process_id":null,"retry_count":0,"last_error_code":null,"updated_at_unix_ms":1}"#;

        assert!(serde_json::from_str::<RuntimeSnapshot>(json).is_err());
    }
}
