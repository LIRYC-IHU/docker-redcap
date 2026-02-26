let wasmReady = null;
let wasmModule = null;

async function ensureWasm() {
    if (wasmReady) {
        return wasmReady;
    }

    wasmReady = import('../wasm/dicom_deid/pkg/dicom_deid.js')
        .then(async (module) => {
            const init = module.default;
            await init();
            wasmModule = module;
            return module;
        });

    return wasmReady;
}

self.onmessage = async (event) => {
    const data = event && event.data ? event.data : {};
    const id = Number(data.id || 0);

    try {
        const module = await ensureWasm();
        if (!module || typeof module.deidentify !== 'function') {
            throw new Error('WASM deidentify function is unavailable.');
        }

        const inputBytes = new Uint8Array(data.bytes || []);
        const recordId = String(data.recordId || '').trim();
        const projectTitle = String(data.projectTitle || '').trim();
        const patientName = projectTitle ? (projectTitle + '^' + recordId) : recordId;
        const output = module.deidentify(inputBytes, recordId, patientName);
        const outputBytes = output instanceof Uint8Array ? output : new Uint8Array(output);

        self.postMessage({
            id,
            ok: true,
            bytes: outputBytes.buffer
        }, [outputBytes.buffer]);
    } catch (error) {
        self.postMessage({
            id,
            ok: false,
            error: error && error.message ? error.message : String(error)
        });
    }
};
