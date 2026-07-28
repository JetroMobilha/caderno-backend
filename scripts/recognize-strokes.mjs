import { readFileSync } from 'fs';
import { execFileSync } from 'child_process';
import { tmpdir } from 'os';
import { join } from 'path';
import { mkdtempSync, rmSync } from 'fs';
import { Jimp } from 'jimp';

const input = readFileSync(0, 'utf8');
const payload = JSON.parse(input || '{}');
const strokes = Array.isArray(payload.strokes) ? payload.strokes : [];
const language = payload.language || 'por';

if (!strokes.length) {
  console.log(JSON.stringify({ text: '', engine: 'tesseract', language }));
  process.exit(0);
}

const width = 1400;
const height = 1800;
const margin = 80;
const image = new Jimp({ width, height, color: 0xFFFFFFFF });

const pointsList = strokes
  .map((stroke) => normalizePoints(stroke))
  .filter((points) => Array.isArray(points) && points.length > 0);

const bounds = pointsList.reduce((acc, points) => {
  points.forEach((point) => {
    const x = Number(point.x ?? point.X ?? 0);
    const y = Number(point.y ?? point.Y ?? 0);
    acc.minX = Math.min(acc.minX, x);
    acc.maxX = Math.max(acc.maxX, x);
    acc.minY = Math.min(acc.minY, y);
    acc.maxY = Math.max(acc.maxY, y);
  });
  return acc;
}, { minX: Number.MAX_SAFE_INTEGER, maxX: Number.MIN_SAFE_INTEGER, minY: Number.MAX_SAFE_INTEGER, maxY: Number.MIN_SAFE_INTEGER });

const rangeX = Math.max(1, bounds.maxX - bounds.minX);
const rangeY = Math.max(1, bounds.maxY - bounds.minY);
const scale = Math.min((width - margin * 2) / rangeX, (height - margin * 2) / rangeY) || 1;
const offsetX = margin - bounds.minX * scale;
const offsetY = margin - bounds.minY * scale;

for (const points of pointsList) {
  if (!Array.isArray(points) || points.length < 2) {
    continue;
  }

  for (let i = 1; i < points.length; i++) {
    const from = points[i - 1];
    const to = points[i];
    const fromX = offsetX + Number(from.x ?? from.X ?? 0) * scale;
    const fromY = offsetY + Number(from.y ?? from.Y ?? 0) * scale;
    const toX = offsetX + Number(to.x ?? to.X ?? 0) * scale;
    const toY = offsetY + Number(to.y ?? to.Y ?? 0) * scale;
    drawLine(image, fromX, fromY, toX, toY, 3);
  }
}

const tempDir = mkdtempSync(join(tmpdir(), 'ocr-strokes-'));
const imagePath = join(tempDir, 'strokes.png');
await image.write(imagePath);

const tesseractPath = process.env.OCR_TESSERACT_PATH || process.env.OCR_TESSERACT_BIN || 'tesseract';
const tessdataPrefix = process.env.OCR_TESSDATA_DIR || process.env.TESSDATA_PREFIX || '';
const args = [imagePath, 'stdout', '--psm', '6'];
if (language) {
  args.push('-l', language);
}

let output = '';
try {
  output = execFileSync(tesseractPath, args, {
    cwd: tempDir,
    env: { ...process.env, TESSDATA_PREFIX: tessdataPrefix },
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
} catch (error) {
  rmSync(tempDir, { recursive: true, force: true });
  throw error;
}

rmSync(tempDir, { recursive: true, force: true });
console.log(JSON.stringify({ text: output.trim(), engine: 'tesseract', language }));

function normalizePoints(stroke) {
  if (Array.isArray(stroke)) {
    return stroke.filter((item) => item && typeof item === 'object');
  }

  if (!stroke || typeof stroke !== 'object') {
    return [];
  }

  if (Array.isArray(stroke.points)) {
    return stroke.points;
  }

  if (Array.isArray(stroke.path)) {
    return stroke.path;
  }

  if (typeof stroke.x !== 'undefined' || typeof stroke.y !== 'undefined') {
    return [stroke];
  }

  return [];
}

function drawLine(image, x1, y1, x2, y2, thickness = 3) {
  const dx = Math.abs(x2 - x1);
  const dy = -Math.abs(y2 - y1);
  const sx = x1 < x2 ? 1 : -1;
  const sy = y1 < y2 ? 1 : -1;
  let err = dx + dy;
  let currentX = x1;
  let currentY = y1;

  while (true) {
    drawPixel(image, Math.round(currentX), Math.round(currentY), thickness);
    if (Math.round(currentX) === Math.round(x2) && Math.round(currentY) === Math.round(y2)) {
      break;
    }

    const e2 = 2 * err;
    if (e2 >= dy) {
      err += dy;
      currentX += sx;
    }
    if (e2 <= dx) {
      err += dx;
      currentY += sy;
    }
  }
}

function drawPixel(image, x, y, thickness) {
  const radius = Math.max(1, Math.floor(thickness / 2));
  for (let offsetX = -radius; offsetX <= radius; offsetX++) {
    for (let offsetY = -radius; offsetY <= radius; offsetY++) {
      const px = Math.round(x + offsetX);
      const py = Math.round(y + offsetY);
      if (px >= 0 && px < 1400 && py >= 0 && py < 1800) {
        image.setPixelColor(0xFF000000, px, py);
      }
    }
  }
}
