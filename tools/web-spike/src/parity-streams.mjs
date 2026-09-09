import { LIMITS } from './parity-contract.mjs';

// The two streams share one retention budget. Never truncate a result into valid-looking JSON.
export async function readProcessResult(response, maximumBytes = LIMITS.outputBytes) {
  let total = 0;
  const readers = [response.stdout.getReader(), response.stderr.getReader()];
  const retained = [[], []];
  const decode = (chunks, fatal = true) => {
    const result = new Uint8Array(chunks.reduce((length, chunk) => length + chunk.byteLength, 0));
    let offset = 0;
    for (const chunk of chunks) { result.set(chunk, offset); offset += chunk.byteLength; }
    return new TextDecoder('utf-8', { fatal }).decode(result);
  };
  const readStream = async (reader, index) => {
    const chunks = retained[index];
    try {
      for (;;) {
        const { done, value } = await reader.read(); if (done) break;
        const data = typeof value === 'string' ? new TextEncoder().encode(value) : value;
        total += data.byteLength;
        if (total > maximumBytes) throw new Error('BP3_OUTPUT_OVERFLOW');
        chunks.push(data);
      }
    } finally { reader.releaseLock(); }
    return decode(chunks);
  };
  try {
    const [stdout, stderr, exitCode] = await Promise.all([...readers.map(readStream), response.exitCode]);
    return { kind: 'completed', stdout, stderr, exitCode };
  } catch (error) {
    for (const reader of readers) { try { reader.cancel().catch(() => {}); } catch { /* Already released. */ } }
    error.observation = { kind: String(error).includes('BP3_OUTPUT_OVERFLOW') ? 'overflow' : 'stream-failure',
      stdout: decode(retained[0], false), stderr: decode(retained[1], false), incomplete: true };
    throw error;
  }
}
