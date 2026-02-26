# dicom_deid (WASM)

Build steps:

1. Install [wasm-pack](https://rustwasm.github.io/wasm-pack/installer/)
2. From module root:

```bash
wasm-pack build wasm/dicom_deid --target web --out-dir pkg
```

This generates:
- `wasm/dicom_deid/pkg/dicom_deid.js`
- `wasm/dicom_deid/pkg/dicom_deid_bg.wasm`

These files are required at runtime by `js/deidentify-worker.js`.
