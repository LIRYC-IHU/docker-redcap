use std::io::Cursor;

use dicom_anonymization::actions::Action;
use dicom_anonymization::config::builder::ConfigBuilder;
use dicom_anonymization::config::uid_root::UidRoot;
use dicom_anonymization::processor::DefaultProcessor;
use dicom_anonymization::tags;
use dicom_anonymization::Anonymizer;

pub fn validate(input_bytes: &[u8]) -> Result<(), String> {
    if input_bytes.is_empty() {
        return Err("input file is empty".to_string());
    }

    let mut verify_cursor = Cursor::new(input_bytes);
    dicom_object::from_reader(&mut verify_cursor)
        .map(|_| ())
        .map_err(|e| format!("input is not a valid DICOM file: {e}"))
}

pub fn deidentify(input_bytes: &[u8], record_id: &str, patient_name: &str) -> Result<Vec<u8>, String> {
    validate(input_bytes).map_err(|e| format!("SKIP: {e}"))?;

    let config = ConfigBuilder::default()
        .uid_root(UidRoot("1.2.826.0.1.3680043.10.543".into()))
        .tag_action(tags::PATIENT_ID, Action::Replace {
            value: record_id.to_string().into(),
        })
        .tag_action(tags::PATIENT_NAME, Action::Replace {
            value: patient_name.to_string().into(),
        })
        .tag_action(tags::DEIDENTIFICATION_METHOD, Action::Replace {
            value: "IHU LIRYC REDCAP PLUGIN".into(),
        })
        .build();

    let processor = DefaultProcessor::new(config);
    let anonymizer = Anonymizer::new(processor);

    let anonymized = anonymizer
        .anonymize(Cursor::new(input_bytes))
        .map_err(|e| format!("SKIP: DICOM anonymization failed: {e}"))?;

    let mut out = Vec::new();
    anonymized
        .write(&mut out)
        .map_err(|e| format!("SKIP: DICOM write failed: {e}"))?;

    Ok(out)
}
