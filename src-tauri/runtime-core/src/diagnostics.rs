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
}
