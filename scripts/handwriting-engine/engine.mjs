import Bezier from 'bezier-js';
import { v4 as uuidv4 } from 'uuid';
import { readFileSync } from 'fs';

let alphabet = {};

export function loadAlphabet(alphabetData) {
  alphabet = alphabetData;
}

export function synthesizeHandwriting(text, options = {}) {
  const { color = '#000000', thickness = 2 } = options;
  const strokes = [];
  let currentX = 0;

  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    const charData = alphabet[char];

    if (!charData) {
        if (char === ' ') {
            currentX += 20; // Default space width
            continue;
        }
        throw new Error(`Character '${char}' not found in alphabet.`);
    }

    charData.strokes.forEach(stroke => {
      const transformedPoints = stroke.points.map(p => ({
        dx: p.dx + currentX,
        dy: p.dy,
      }));
      strokes.push({
        id: uuidv4(),
        color,
        thickness,
        points: transformedPoints,
      });
    });

    currentX += charData.width;

    if (i < text.length - 1) {
      const nextChar = text[i + 1];
      const nextCharData = alphabet[nextChar];

      const shouldConnect = nextCharData && charData.exitPoint && nextCharData.entryPoint;

      if (shouldConnect) {
        const startX = (currentX - charData.width) + charData.exitPoint.dx;
        const startY = charData.exitPoint.dy;
        const endX = currentX + nextCharData.entryPoint.dx;
        const endY = nextCharData.entryPoint.dy;

        // --- Lógica da Curva de Bézier para conectar as letras ---
        const H_EXTEND = 10;
        const V_ADJUST_MAGNITUDE = 10;
        let cp1y = startY;
        let cp2y = endY;

        if (startY < endY) {
          cp1y = startY + V_ADJUST_MAGNITUDE;
          cp2y = endY - V_ADJUST_MAGNITUDE;
        } else if (startY > endY) {
          cp1y = startY - V_ADJUST_MAGNITUDE;
          cp2y = endY + V_ADJUST_MAGNITUDE;
        }

        const cp1x = startX + H_EXTEND;
        const cp2x = endX - H_EXTEND;

        const bezierCurve = new Bezier([
          { x: startX, y: startY },
          { x: cp1x, y: cp1y },
          { x: cp2x, y: cp2y },
          { x: endX, y: endY },
        ]);

        const numSegments = 20;
        const curvePoints = [];
        for (let j = 0; j <= numSegments; j++) {
          const t = j / numSegments;
          const point = bezierCurve.get(t);
          curvePoints.push({ dx: point.x, dy: point.y });
        }

        strokes.push({
          id: uuidv4(),
          color,
          thickness,
          points: curvePoints,
        });
      } else {
        // Se não houver conexão (ex: letra seguida de número), adiciona um pequeno espaço extra.
        const kerning = 5; 
        currentX += kerning;
      }
    }
  }

  return strokes;
}

// Main execution block when script is run directly
if (import.meta.url === `file://${process.argv[1]}`) {
  const defaultAlphabetPath = process.argv[2];
  const text = process.argv[3];
  const color = process.argv[4];
  const thickness = parseFloat(process.argv[5]);
  const customAlphabetBase64 = process.argv[6];

  try {
    let alphabetData;
    if (customAlphabetBase64) {
      const decodedAlphabet = Buffer.from(customAlphabetBase64, 'base64').toString('utf8');
      alphabetData = JSON.parse(decodedAlphabet);
    } else {
      const defaultAlphabetContent = readFileSync(defaultAlphabetPath, 'utf8');
      alphabetData = JSON.parse(defaultAlphabetContent);
    }

    loadAlphabet(alphabetData);

    const generatedStrokes = synthesizeHandwriting(text, { color, thickness });
    console.log(JSON.stringify(generatedStrokes));

  } catch (error) {
    console.error(`Error in handwriting synthesis engine: ${error.message}`);
    process.exit(1);
  }
}
