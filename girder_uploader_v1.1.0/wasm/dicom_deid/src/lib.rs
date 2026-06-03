mod dicom;
mod schiller;
mod xml;

use wasm_bindgen::prelude::*;

#[wasm_bindgen]
pub struct DeidentifyResult {
    bytes: Vec<u8>,
    format_name: String,
    mime_type: String,
}

#[wasm_bindgen]
impl DeidentifyResult {
    #[wasm_bindgen(js_name = bytes)]
    pub fn bytes_js(&self) -> Vec<u8> {
        self.bytes.clone()
    }

    #[wasm_bindgen(js_name = formatName)]
    pub fn format_name_js(&self) -> String {
        self.format_name.clone()
    }

    #[wasm_bindgen(js_name = mimeType)]
    pub fn mime_type_js(&self) -> String {
        self.mime_type.clone()
    }
}

#[wasm_bindgen]
pub fn deidentify(
    input_bytes: &[u8],
    file_name: String,
    record_id: String,
    patient_name: String,
    enable_dicom: bool,
    enable_xml: bool,
    enable_schiller: bool,
) -> Result<DeidentifyResult, JsValue> {
    let normalized_record_id = if record_id.trim().is_empty() {
        "UNASSIGNED_RECORD".to_string()
    } else {
        record_id.trim().to_string()
    };
    let normalized_patient_name = if patient_name.trim().is_empty() {
        format!("REDCAP_PROJECT^{}", normalized_record_id)
    } else {
        patient_name.trim().to_string()
    };

    if schiller::validate(input_bytes).is_ok() {
        if enable_schiller {
            let output = schiller::deidentify(input_bytes, &normalized_record_id)
                .map_err(|e| JsValue::from_str(&e))?;
            return Ok(DeidentifyResult {
                bytes: output,
                format_name: "schiller".to_string(),
                mime_type: "application/octet-stream".to_string(),
            });
        }

        return Ok(DeidentifyResult {
            bytes: input_bytes.to_vec(),
            format_name: "schiller".to_string(),
            mime_type: "application/octet-stream".to_string(),
        });
    }

    if xml::validate(input_bytes, &file_name).is_ok() {
        if enable_xml {
            let output = xml::deidentify(input_bytes, &file_name, &normalized_record_id)
                .map_err(|e| JsValue::from_str(&e))?;
            return Ok(DeidentifyResult {
                bytes: output,
                format_name: "xml".to_string(),
                mime_type: "application/xml".to_string(),
            });
        }

        return Ok(DeidentifyResult {
            bytes: input_bytes.to_vec(),
            format_name: "xml".to_string(),
            mime_type: "application/xml".to_string(),
        });
    }

    if dicom::validate(input_bytes).is_ok() {
        if enable_dicom {
            let output = dicom::deidentify(input_bytes, &normalized_record_id, &normalized_patient_name)
                .map_err(|e| JsValue::from_str(&e))?;
            return Ok(DeidentifyResult {
                bytes: output,
                format_name: "dicom".to_string(),
                mime_type: "application/dicom".to_string(),
            });
        }

        return Ok(DeidentifyResult {
            bytes: input_bytes.to_vec(),
            format_name: "dicom".to_string(),
            mime_type: "application/dicom".to_string(),
        });
    }

    Err(JsValue::from_str(
        "SKIP: file is unsupported",
    ))
}
