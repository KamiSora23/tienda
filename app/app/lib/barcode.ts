// Códigos de barras escaneables: EAN-13 (el de los productos de supermercado) y Code 128
// (cualquier texto, p. ej. un SKU). Se dibujan como SVG para imprimir etiquetas.

// --- EAN-13 ---------------------------------------------------------------------------
const EAN_L = ["0001101", "0011001", "0010011", "0111101", "0100011", "0110001", "0101111", "0111011", "0110111", "0001011"];
const EAN_G = ["0100111", "0110011", "0011011", "0100001", "0011101", "0111001", "0000101", "0010001", "0001001", "0010111"];
const EAN_R = ["1110010", "1100110", "1101100", "1000010", "1011100", "1001110", "1010000", "1000100", "1001000", "1110100"];
// Paridad L/G de los 6 primeros dígitos según el dígito inicial.
const EAN_PARITY = ["LLLLLL", "LLGLGG", "LLGGLG", "LLGGGL", "LGLLGG", "LGGLLG", "LGGGLL", "LGLGLG", "LGLGGL", "LGGLGL"];

/** Dígito de control EAN-13 para los 12 primeros dígitos. */
export function ean13CheckDigit(first12: string) {
  const sum = [...first12].reduce((s, d, i) => s + Number(d) * (i % 2 ? 3 : 1), 0);
  return String((10 - (sum % 10)) % 10);
}

export const isValidEan13 = (code: string) => /^\d{13}$/.test(code) && ean13CheckDigit(code.slice(0, 12)) === code[12];

/**
 * Código interno para productos sin código de fábrica. El prefijo 20 está reservado para uso
 * interno de la tienda (GS1), así que nunca coincide con un producto comercial.
 */
export function internalEan13() {
  const body = "20" + String(Date.now()).slice(-10);
  return body + ean13CheckDigit(body);
}

function ean13Modules(code: string) {
  const parity = EAN_PARITY[Number(code[0])];
  let bits = "101";
  for (let i = 1; i <= 6; i++) bits += (parity[i - 1] === "L" ? EAN_L : EAN_G)[Number(code[i])];
  bits += "01010";
  for (let i = 7; i <= 12; i++) bits += EAN_R[Number(code[i])];
  return bits + "101";
}

// --- Code 128 (juego B: letras, números y signos) -------------------------------------
// Anchos barra/espacio de cada símbolo 0–106 (106 = STOP, que lleva una barra final extra).
const C128 = (
  "212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 " +
  "221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 " +
  "231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 " +
  "314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 " +
  "111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 " +
  "114131 311141 411131 211412 211214 211232 2331112"
).split(" ");

function code128Modules(text: string) {
  const values = [104, ...[...text].map((ch) => {
    const c = ch.charCodeAt(0);
    if (c < 32 || c > 127) throw new Error(`Carácter no válido para Code 128: ${ch}`);
    return c - 32;
  })];
  const checksum = values.reduce((s, v, i) => s + v * (i || 1), 0) % 103;
  let bits = "";
  for (const v of [...values, checksum, 106]) {
    [...C128[v]].forEach((w, i) => { bits += (i % 2 ? "0" : "1").repeat(Number(w)); });
  }
  return bits;
}

/** SVG del código: EAN-13 si es válido; en otro caso Code 128. */
export function barcodeSvg(code: string, height = 56) {
  const ean = isValidEan13(code);
  const bits = ean ? ean13Modules(code) : code128Modules(code);
  const quiet = 10, width = bits.length + quiet * 2;
  let rects = "";
  for (let i = 0; i < bits.length; ) {
    if (bits[i] === "1") {
      let j = i; while (bits[j] === "1") j++;
      rects += `<rect x="${i + quiet}" y="0" width="${j - i}" height="${height}"/>`;
      i = j;
    } else i++;
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height + 14}" width="${width * 2}" height="${(height + 14) * 2}" role="img" aria-label="Código ${code}">` +
    `<rect width="100%" height="100%" fill="#fff"/><g fill="#000">${rects}</g>` +
    `<text x="${width / 2}" y="${height + 11}" font-family="monospace" font-size="10" text-anchor="middle">${code}</text></svg>`;
}
