use std::collections::HashMap;

use quick_xml::events::{BytesEnd, BytesStart, BytesText, Event};
use quick_xml::{Reader, Writer};

enum XmlType {
    Hl7V2,
    Hl7V3,
    PhilipsEcg,
}

pub fn validate(input_bytes: &[u8], file_name: &str) -> Result<(), String> {
    let mut anonymizer = XmlAnonymizer::from_bytes(input_bytes, file_name)
        .map_err(|e| e.strip_prefix("SKIP: ").unwrap_or(&e).to_string())?;
    anonymizer
        .define_type()
        .map_err(|e| e.strip_prefix("SKIP: ").unwrap_or(&e).to_string())?;
    Ok(())
}

pub fn deidentify(input_bytes: &[u8], file_name: &str, record_id: &str) -> Result<Vec<u8>, String> {
    let mut anonymizer = XmlAnonymizer::from_bytes(input_bytes, file_name)?;
    anonymizer.define_type()?;
    anonymizer.anonymize(record_id);
    anonymizer.to_bytes()
}

struct XmlAnonymizer {
    bytes: Vec<u8>,
    replacements: HashMap<String, String>,
    xml_type: XmlType,
}

const FIELDS_TO_ANONYMIZE: &[&str] = &[
    "patientid",
    "secondpatientid",
    "firstname",
    "lastname",
    "name",
    "age",
    "bed",
    "room",
    "sexe",
    "sex",
    "pointofcare",
    "patientname",
    "surname",
    "givenname",
    "technician",
    "doctor",
    "operator",
    "middlename",
    "viperuniquepatientid",
];

impl XmlAnonymizer {
    fn from_bytes(bytes: &[u8], file_name: &str) -> Result<Self, String> {
        if !file_name.to_lowercase().ends_with(".xml") {
            return Err("SKIP: input is not an XML ECG file".to_string());
        }

        let mut reader = Reader::from_reader(bytes);
        let mut path = Vec::new();
        let mut replacements = HashMap::new();

        loop {
            match reader.read_event() {
                Ok(Event::Start(e)) => {
                    let tag = String::from_utf8_lossy(e.name().as_ref()).to_string();
                    path.push(tag.clone());

                    for attr in e.attributes().flatten() {
                        let key = format!(
                            "{}/@{}",
                            path.join("/"),
                            String::from_utf8_lossy(attr.key.as_ref())
                        );
                        let value = attr.unescape_value().unwrap_or_default().to_string();
                        replacements.insert(key, value);
                    }
                }
                Ok(Event::Empty(e)) => {
                    let tag = String::from_utf8_lossy(e.name().as_ref()).to_string();
                    let mut element_path = path.clone();
                    element_path.push(tag);

                    for attr in e.attributes().flatten() {
                        let key = format!(
                            "{}/@{}",
                            element_path.join("/"),
                            String::from_utf8_lossy(attr.key.as_ref())
                        );
                        let value = attr.unescape_value().unwrap_or_default().to_string();
                        replacements.insert(key, value);
                    }
                }
                Ok(Event::Text(e)) => {
                    let text = e.unescape().unwrap_or_default().to_string();
                    if !text.trim().is_empty() {
                        replacements.insert(path.join("/"), text);
                    }
                }
                Ok(Event::End(_)) => {
                    path.pop();
                }
                Ok(Event::Eof) => break,
                Err(e) => return Err(format!("SKIP: XML parsing failed: {e}")),
                _ => {}
            }
        }

        if replacements.is_empty() {
            return Err("SKIP: XML file did not contain readable ECG content".to_string());
        }

        Ok(Self {
            bytes: bytes.to_vec(),
            replacements,
            xml_type: XmlType::Hl7V3,
        })
    }

    fn define_type(&mut self) -> Result<(), String> {
        for (key, value) in &self.replacements {
            if key == "AnnotatedECG/@xmlns" {
                match value.as_str() {
                    "urn:hl7-org:v2" => {
                        self.xml_type = XmlType::Hl7V2;
                        return Ok(());
                    }
                    "urn:hl7-org:v3" => {
                        self.xml_type = XmlType::Hl7V3;
                        return Ok(());
                    }
                    _ => {}
                }
            }

            if key == "restingecgdata/@xmlns" && value.contains("medical.philips.com") {
                self.xml_type = XmlType::PhilipsEcg;
                return Ok(());
            }
        }

        Err("SKIP: unsupported XML ECG format".to_string())
    }

    fn anonymize(&mut self, record_id: &str) {
        let replacement_id = record_id.trim().to_string();
        let keys: Vec<String> = self.replacements.keys().cloned().collect();

        for key in keys {
            let key_lower = key.to_lowercase();
            let should_anonymize = FIELDS_TO_ANONYMIZE.iter().any(|pattern| {
                if !key_lower.contains(pattern) {
                    return false;
                }

                let last_part = key_lower.rsplit('/').next().unwrap_or("");
                if last_part.starts_with('@') {
                    let attr_name = &last_part[1..];
                    return !(attr_name.ends_with("existflag") || attr_name.ends_with("flag"));
                }

                true
            });

            if !should_anonymize {
                continue;
            }

            if key_lower.contains("patientid") {
                self.replacements.insert(key, replacement_id.clone());
            } else {
                self.replacements.insert(key, String::new());
            }
        }
    }

    fn to_bytes(&self) -> Result<Vec<u8>, String> {
        let mut reader = Reader::from_reader(self.bytes.as_slice());
        let mut output = Vec::new();

        if self.bytes.starts_with(b"\xef\xbb\xbf") {
            output.extend_from_slice(b"\xef\xbb\xbf");
        }

        let mut writer = Writer::new(output);
        let mut path = Vec::new();

        loop {
            match reader.read_event() {
                Ok(Event::Decl(e)) => {
                    writer
                        .write_event(Event::Decl(e))
                        .map_err(|err| format!("SKIP: XML declaration write failed: {err}"))?;
                }
                Ok(Event::Start(e)) => {
                    let tag = String::from_utf8_lossy(e.name().as_ref()).to_string();
                    path.push(tag.clone());
                    let mut new_event = BytesStart::new(tag.as_str());

                    for attr in e.attributes().flatten() {
                        let attr_name = String::from_utf8_lossy(attr.key.as_ref()).to_string();
                        let key = format!("{}/@{}", path.join("/"), attr_name);
                        let value = self
                            .replacements
                            .get(&key)
                            .cloned()
                            .unwrap_or_else(|| attr.unescape_value().unwrap_or_default().to_string());
                        new_event.push_attribute((attr_name.as_str(), value.as_str()));
                    }

                    writer
                        .write_event(Event::Start(new_event))
                        .map_err(|err| format!("SKIP: XML start-element write failed: {err}"))?;
                }
                Ok(Event::Text(e)) => {
                    let key = path.join("/");
                    let original_text = e.unescape().unwrap_or_default().to_string();
                    let text_to_write = self.replacements.get(&key).cloned().unwrap_or(original_text);

                    if !text_to_write.is_empty() {
                        writer
                            .write_event(Event::Text(BytesText::new(&text_to_write)))
                            .map_err(|err| format!("SKIP: XML text write failed: {err}"))?;
                    }
                }
                Ok(Event::End(e)) => {
                    let tag = String::from_utf8_lossy(e.name().as_ref()).to_string();
                    writer
                        .write_event(Event::End(BytesEnd::new(tag)))
                        .map_err(|err| format!("SKIP: XML end-element write failed: {err}"))?;
                    path.pop();
                }
                Ok(Event::Empty(e)) => {
                    let tag = String::from_utf8_lossy(e.name().as_ref()).to_string();
                    let mut new_event = BytesStart::new(tag.as_str());

                    for attr in e.attributes().flatten() {
                        let attr_name = String::from_utf8_lossy(attr.key.as_ref()).to_string();
                        let key = format!("{}/{}/@{}", path.join("/"), tag, attr_name);
                        let value = self
                            .replacements
                            .get(&key)
                            .cloned()
                            .unwrap_or_else(|| attr.unescape_value().unwrap_or_default().to_string());
                        new_event.push_attribute((attr_name.as_str(), value.as_str()));
                    }

                    writer
                        .write_event(Event::Empty(new_event))
                        .map_err(|err| format!("SKIP: XML empty-element write failed: {err}"))?;
                }
                Ok(Event::Comment(e)) => {
                    writer
                        .write_event(Event::Comment(e))
                        .map_err(|err| format!("SKIP: XML comment write failed: {err}"))?;
                }
                Ok(Event::CData(e)) => {
                    writer
                        .write_event(Event::CData(e))
                        .map_err(|err| format!("SKIP: XML CDATA write failed: {err}"))?;
                }
                Ok(Event::Eof) => break,
                Err(err) => return Err(format!("SKIP: XML serialization failed: {err}")),
                _ => {}
            }
        }

        Ok(writer.into_inner())
    }
}
