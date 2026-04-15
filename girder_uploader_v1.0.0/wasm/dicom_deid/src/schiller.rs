use uuid::Uuid;

const SCHILLER_MAGIC_NUMBER: &[u8] = &[
    0x00, 0x55, 0xDA, 0xBA, 0x01, 0x00, 0x63, 0x00, 0x60, 0x43, 0x54, 0x43, 0x41, 0x43, 0x55, 0x10,
];
const AUDIO_START: u64 = 0x1800;
const AUDIO_END: u64 = 0xA1800;
const CHUNK_START: usize = 0x9FE;
const CHUNK_END: usize = 0xBFF;
const CHECKSUM_OFFSET: usize = 0xBFE;
const CRC_START: usize = 0xA00;
const UUID_OFFSET: usize = 0xBD4;
const XOR_KEY: u8 = 0x8A;

pub fn validate(input_bytes: &[u8]) -> Result<(), String> {
    Deidentified::from_bytes(input_bytes.to_vec()).map(|_| ())
}

pub fn deidentify(input_bytes: &[u8], record_id: &str) -> Result<Vec<u8>, String> {
    let mut container = Deidentified::from_bytes(input_bytes.to_vec())
        .map_err(|e| format!("SKIP: {e}"))?;

    if !record_id.trim().is_empty() {
        container.set_patient_id(record_id.trim());
    }

    Ok(container.into_bytes())
}

struct Deidentified {
    buffer: Vec<u8>,
    anonymous_id: String,
}

impl Deidentified {
    fn from_bytes(bytes: Vec<u8>) -> Result<Self, String> {
        let mut schiller = Self {
            buffer: bytes,
            anonymous_id: String::new(),
        };

        schiller.check_magic_number()?;
        let audio_data = vec![0u8; (AUDIO_END - AUDIO_START) as usize];
        schiller.overwrite_audio_section(&audio_data)?;
        schiller.buffer[CHUNK_START..CHUNK_END].fill(0xAA);
        schiller.anonymize_patient_info()?;

        Ok(schiller)
    }

    fn into_bytes(self) -> Vec<u8> {
        self.buffer
    }

    fn check_magic_number(&self) -> Result<(), String> {
        if self.buffer.len() < SCHILLER_MAGIC_NUMBER.len() {
            return Err("invalid Schiller file size".to_string());
        }

        if &self.buffer[..SCHILLER_MAGIC_NUMBER.len()] == SCHILLER_MAGIC_NUMBER {
            Ok(())
        } else {
            Err("input is not a Schiller Holter file".to_string())
        }
    }

    fn overwrite_audio_section(&mut self, new_audio_data: &[u8]) -> Result<(), String> {
        let audio_section_size = (AUDIO_END - AUDIO_START) as usize;
        if new_audio_data.len() != audio_section_size {
            return Err("invalid Schiller audio section size".to_string());
        }

        let start = AUDIO_START as usize;
        let end = AUDIO_END as usize;
        self.buffer[start..end].copy_from_slice(new_audio_data);
        Ok(())
    }

    fn set_patient_id(&mut self, patient_id: &str) {
        self.anonymous_id = patient_id.to_string();
    }

    fn anonymize_patient_info(&mut self) -> Result<(), String> {
        self.buffer[0x09FE] = 0xCC;
        self.buffer[0x09FF] = 0x69;
        self.buffer[0x0A00] = 0x01;
        self.buffer[0x0A01] = 0x01;

        let mut numeric_id = if !self.anonymous_id.is_empty() {
            self.anonymous_id.clone()
        } else {
            random_numeric_string(28)
        };

        if numeric_id.len() > 28 {
            numeric_id.truncate(28);
        }

        write_xor_field(&mut self.buffer, 0x0B2B, 28, &numeric_id);
        self.anonymous_id = numeric_id;

        let new_uuid = generate_braced_uuid();
        self.buffer[UUID_OFFSET..UUID_OFFSET + 38].copy_from_slice(new_uuid.as_bytes());
        self.buffer[UUID_OFFSET + 38..UUID_OFFSET + 42].fill(0x00);

        self.update_checksum()?;
        self.verify_checksum()?;

        Ok(())
    }

    fn update_checksum(&mut self) -> Result<(), String> {
        if self.buffer.len() < CHECKSUM_OFFSET + 2 {
            return Err("invalid Schiller checksum offset".to_string());
        }

        let crc = calculate_crc16_ccitt(&self.buffer[CRC_START..CHECKSUM_OFFSET]);
        let crc_bytes = crc.to_be_bytes();
        self.buffer[CHECKSUM_OFFSET] = crc_bytes[0];
        self.buffer[CHECKSUM_OFFSET + 1] = crc_bytes[1];
        Ok(())
    }

    fn verify_checksum(&self) -> Result<(), String> {
        if self.buffer.len() < CHECKSUM_OFFSET + 2 {
            return Err("invalid Schiller checksum offset".to_string());
        }

        let stored_crc = u16::from_be_bytes([
            self.buffer[CHECKSUM_OFFSET],
            self.buffer[CHECKSUM_OFFSET + 1],
        ]);
        let calculated_crc = calculate_crc16_ccitt(&self.buffer[CRC_START..CHECKSUM_OFFSET]);

        if stored_crc != calculated_crc {
            return Err("invalid Schiller checksum".to_string());
        }

        Ok(())
    }
}

fn write_xor_field(buf: &mut [u8], offset: usize, length: usize, value: &str) {
    let mut encoded = Vec::new();
    for b in value.bytes() {
        encoded.push(b ^ XOR_KEY);
    }

    while encoded.len() < length {
        encoded.push(0xAA);
    }

    encoded.truncate(length);
    buf[offset..offset + length].copy_from_slice(&encoded);
}

fn random_numeric_string(len: usize) -> String {
    let uuid_hex: String = Uuid::new_v4()
        .to_string()
        .chars()
        .filter(|c| *c != '-')
        .collect();

    let bytes = uuid_hex.as_bytes();
    let mut out = String::with_capacity(len);
    let mut idx = 0usize;

    while out.len() < len {
        let c = bytes[idx % bytes.len()];
        let digit = match c {
            b'0'..=b'9' => c - b'0',
            b'a'..=b'f' => (c - b'a') % 10,
            b'A'..=b'F' => (c - b'A') % 10,
            _ => 0,
        };
        out.push(char::from(b'0' + digit));
        idx += 1;
    }

    out
}

fn calculate_crc16_ccitt(data: &[u8]) -> u16 {
    const POLYNOMIAL: u16 = 0x1021;
    let mut crc: u16 = 0xFFFF;

    for &byte in data {
        crc ^= (byte as u16) << 8;
        for _ in 0..8 {
            if crc & 0x8000 != 0 {
                crc = (crc << 1) ^ POLYNOMIAL;
            } else {
                crc <<= 1;
            }
        }
    }

    crc.to_be()
}

fn generate_braced_uuid() -> String {
    format!("{{{}}}", Uuid::new_v4().to_string().to_uppercase())
}
