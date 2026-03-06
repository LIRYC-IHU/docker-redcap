use std::io::Cursor;
use wasm_bindgen::prelude::*;

use dicom_anonymization::actions::Action;
use dicom_anonymization::config::builder::ConfigBuilder;
use dicom_anonymization::config::uid_root::UidRoot;
use dicom_anonymization::processor::DefaultProcessor;
use dicom_anonymization::tags;
use dicom_anonymization::Anonymizer;

#[wasm_bindgen]
pub fn deidentify(input_bytes: &[u8], record_id: String, patient_name: String) -> Result<Vec<u8>, JsValue> {
    if input_bytes.is_empty() {
        return Err(JsValue::from_str("Input file is empty."));
    }

    // First pass: verify the payload is a readable DICOM object.
    // This enforces strict filtering when deidentification is enabled.
    let mut verify_cursor = Cursor::new(input_bytes);
    dicom_object::from_reader(&mut verify_cursor)
        .map_err(|e| JsValue::from_str(&format!("Input is not a valid DICOM file: {e}")))?;

    let record_id = if record_id.trim().is_empty() {
        "UNASSIGNED_RECORD".to_string()
    } else {
        record_id
    };

    let patient_name = if patient_name.trim().is_empty() {
        format!("REDCAP_PROJECT^{}", record_id)
    } else {
        patient_name
    };

    let config = ConfigBuilder::default()
        .uid_root(UidRoot("1.2.826.0.1.3680043.10.543".into()))
        .tag_action(tags::PATIENT_ID, Action::Replace {
            value: record_id.into(),
        })
        .tag_action(tags::PATIENT_NAME, Action::Replace {
            value: patient_name.into(),
        })
        .tag_action(tags::DEIDENTIFICATION_METHOD, Action::Replace {
            value: "IHU LIRYC REDCAP PLUGIN".into(),
        })
        .build();

    let processor = DefaultProcessor::new(config);
    let anonymizer = Anonymizer::new(processor);

    let anonymized = anonymizer
        .anonymize(Cursor::new(input_bytes))
        .map_err(|e| JsValue::from_str(&format!("Anonymization failed: {e}")))?;

    let mut out = Vec::new();
    anonymized
        .write(&mut out)
        .map_err(|e| JsValue::from_str(&format!("Write failed: {e}")))?;

    Ok(out)
}
