use std::{
    fs::{self, OpenOptions},
    io::{self, Write},
    path::{Path, PathBuf},
};

use base64::{engine::general_purpose::STANDARD, Engine as _};
use rand::RngCore;
use serde::{Deserialize, Serialize};
use uuid::Uuid;

const IDENTITY_SCHEMA_VERSION: u8 = 1;

#[derive(Clone, Debug)]
pub struct InstallationIdentity {
    pub installation_id: Uuid,
    pub app_key: String,
}

#[derive(Debug, Deserialize, Serialize)]
struct PublicIdentity {
    schema_version: u8,
    installation_id: Uuid,
}

pub fn load_or_create_installation_identity(
    configuration_directory: &Path,
) -> io::Result<InstallationIdentity> {
    fs::create_dir_all(configuration_directory)?;

    let identity_path = configuration_directory.join("installation.json");
    let key_path = configuration_directory.join("laravel.key");

    match (read_identity(&identity_path), read_key(&key_path)) {
        (Ok(identity), Ok(app_key)) => Ok(InstallationIdentity {
            installation_id: identity.installation_id,
            app_key,
        }),
        (Err(identity_error), Err(key_error))
            if identity_error.kind() == io::ErrorKind::NotFound
                && key_error.kind() == io::ErrorKind::NotFound =>
        {
            create_identity(&identity_path, &key_path)
        }
        _ => Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "installation identity is incomplete or invalid",
        )),
    }
}

fn read_identity(path: &Path) -> io::Result<PublicIdentity> {
    ensure_regular_file(path)?;
    let bytes = fs::read(path)?;
    let identity: PublicIdentity = serde_json::from_slice(&bytes)
        .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error))?;

    if identity.schema_version != IDENTITY_SCHEMA_VERSION {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "unsupported installation identity schema",
        ));
    }

    Ok(identity)
}

fn read_key(path: &Path) -> io::Result<String> {
    ensure_regular_file(path)?;
    let value = fs::read_to_string(path)?;
    let value = unprotect_stored_key(value.trim())?;
    let encoded = value
        .strip_prefix("base64:")
        .ok_or_else(|| io::Error::new(io::ErrorKind::InvalidData, "invalid Laravel key prefix"))?;
    let decoded = STANDARD
        .decode(encoded)
        .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error))?;

    if decoded.len() != 32 {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "invalid Laravel key length",
        ));
    }

    Ok(value)
}

fn create_identity(identity_path: &Path, key_path: &Path) -> io::Result<InstallationIdentity> {
    let installation_id = Uuid::new_v4();
    let mut key_material = [0_u8; 32];
    rand::rng().fill_bytes(&mut key_material);
    let app_key = format!("base64:{}", STANDARD.encode(key_material));

    let stored_key = protect_key_for_storage(&app_key)?;
    write_new_private_file(key_path, format!("{stored_key}\n").as_bytes())?;

    let public_identity = PublicIdentity {
        schema_version: IDENTITY_SCHEMA_VERSION,
        installation_id,
    };
    let result = serde_json::to_vec_pretty(&public_identity)
        .map_err(io::Error::other)
        .and_then(|bytes| write_new_private_file(identity_path, &bytes));

    if let Err(error) = result {
        // This key was created by this function and has never been used. Avoid
        // leaving a half-created identity which could make later data unreadable.
        let _ = fs::remove_file(key_path);
        return Err(error);
    }

    Ok(InstallationIdentity {
        installation_id,
        app_key,
    })
}

fn write_new_private_file(path: &Path, contents: &[u8]) -> io::Result<()> {
    let temporary_path = temporary_path_for(path);
    let mut options = OpenOptions::new();
    options.write(true).create_new(true);

    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }

    let mut file = options.open(&temporary_path)?;
    if let Err(error) = (|| {
        file.write_all(contents)?;
        file.sync_all()?;
        drop(file);
        fs::rename(&temporary_path, path)
    })() {
        let _ = fs::remove_file(&temporary_path);
        return Err(error);
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

fn ensure_regular_file(path: &Path) -> io::Result<()> {
    let file_type = fs::symlink_metadata(path)?.file_type();
    if file_type.is_symlink() || !file_type.is_file() {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "installation identity path is not a regular file",
        ));
    }

    Ok(())
}

#[cfg(not(windows))]
fn protect_key_for_storage(app_key: &str) -> io::Result<String> {
    Ok(app_key.to_owned())
}

#[cfg(not(windows))]
fn unprotect_stored_key(value: &str) -> io::Result<String> {
    Ok(value.to_owned())
}

#[cfg(windows)]
fn protect_key_for_storage(app_key: &str) -> io::Result<String> {
    use std::ptr::null;

    use windows_sys::Win32::{
        Foundation::LocalFree,
        Security::Cryptography::{CryptProtectData, CRYPTPROTECT_UI_FORBIDDEN, CRYPT_INTEGER_BLOB},
    };

    let length = u32::try_from(app_key.len())
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "Laravel key is too large"))?;
    let input = CRYPT_INTEGER_BLOB {
        cbData: length,
        pbData: app_key.as_ptr().cast_mut(),
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
            "DPAPI returned an empty protected key",
        ));
    }

    let ciphertext = unsafe {
        let bytes = std::slice::from_raw_parts(output.pbData, output.cbData as usize);
        let encoded = STANDARD.encode(bytes);
        LocalFree(output.pbData.cast());
        encoded
    };

    Ok(format!("dpapi:{ciphertext}"))
}

#[cfg(windows)]
fn unprotect_stored_key(value: &str) -> io::Result<String> {
    use std::ptr::{null, null_mut};

    use windows_sys::Win32::{
        Foundation::LocalFree,
        Security::Cryptography::{
            CryptUnprotectData, CRYPTPROTECT_UI_FORBIDDEN, CRYPT_INTEGER_BLOB,
        },
    };

    let encoded = value.strip_prefix("dpapi:").ok_or_else(|| {
        io::Error::new(io::ErrorKind::InvalidData, "invalid protected key prefix")
    })?;
    let mut ciphertext = STANDARD
        .decode(encoded)
        .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error))?;
    let length = u32::try_from(ciphertext.len())
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "protected key is too large"))?;
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
    if unprotected == 0 {
        return Err(io::Error::last_os_error());
    }
    if output.pbData.is_null() || output.cbData == 0 {
        if !output.pbData.is_null() {
            unsafe { LocalFree(output.pbData.cast()) };
        }
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "DPAPI returned an empty Laravel key",
        ));
    }

    let plaintext = unsafe {
        let bytes = std::slice::from_raw_parts(output.pbData, output.cbData as usize);
        let result = String::from_utf8(bytes.to_vec())
            .map_err(|error| io::Error::new(io::ErrorKind::InvalidData, error));
        LocalFree(output.pbData.cast());
        result
    }?;

    Ok(plaintext)
}

#[cfg(test)]
mod tests {
    use std::fs;

    use super::*;

    fn temporary_directory() -> PathBuf {
        let path = std::env::temp_dir().join(format!("medismart-installation-{}", Uuid::new_v4()));
        fs::create_dir_all(&path).unwrap();
        path
    }

    #[test]
    fn installation_identity_is_created_once_and_remains_stable() {
        let directory = temporary_directory();

        let first = load_or_create_installation_identity(&directory).unwrap();
        let second = load_or_create_installation_identity(&directory).unwrap();

        assert_eq!(first.installation_id, second.installation_id);
        assert_eq!(first.app_key, second.app_key);
        assert!(first.app_key.starts_with("base64:"));
        assert!(!fs::read_to_string(directory.join("installation.json"))
            .unwrap()
            .contains(&first.app_key));

        fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn incomplete_identity_is_rejected_instead_of_rotating_the_key() {
        let directory = temporary_directory();
        fs::write(directory.join("installation.json"), b"{}").unwrap();

        let error = load_or_create_installation_identity(&directory).unwrap_err();

        assert_eq!(error.kind(), io::ErrorKind::InvalidData);
        assert!(!directory.join("laravel.key").exists());
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn installation_files_are_user_only_on_unix() {
        use std::os::unix::fs::PermissionsExt;

        let directory = temporary_directory();
        load_or_create_installation_identity(&directory).unwrap();

        let mode = fs::metadata(directory.join("laravel.key"))
            .unwrap()
            .permissions()
            .mode()
            & 0o777;

        assert_eq!(mode, 0o600);
        fs::remove_dir_all(directory).unwrap();
    }

    #[cfg(unix)]
    #[test]
    fn installation_key_symlinks_are_rejected() {
        use std::os::unix::fs::symlink;

        let directory = temporary_directory();
        let identity = load_or_create_installation_identity(&directory).unwrap();
        let external = directory.join("external-key");
        fs::write(&external, &identity.app_key).unwrap();
        fs::remove_file(directory.join("laravel.key")).unwrap();
        symlink(&external, directory.join("laravel.key")).unwrap();

        let error = load_or_create_installation_identity(&directory).unwrap_err();

        assert_eq!(error.kind(), io::ErrorKind::InvalidData);
        fs::remove_dir_all(directory).unwrap();
    }
}
