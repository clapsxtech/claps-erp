import {useMemo} from 'react';
import {qrMatrix} from './qr';

/** Renders text as a QR code SVG, encoded in the browser. */
export function QrCode({text, size = 200, label}: {text: string; size?: number; label: string}) {
  const result = useMemo(() => {
    let matrix: boolean[][];
    try {
      matrix = qrMatrix(text);
    } catch {
      return null; // Too long for this encoder: the manual key beside it still works.
    }
    const quiet = 4;
    let d = '';
    matrix.forEach((row, y) => row.forEach((dark, x) => {
      if (dark) d += `M${x + quiet} ${y + quiet}h1v1h-1z`;
    }));
    return {path: d, dim: matrix.length + quiet * 2};
  }, [text]);
  if (!result) return null;
  const {path, dim} = result;
  // Always dark on light, whatever the theme: scanners expect it.
  return (
    <svg role="img" aria-label={label} width={size} height={size} viewBox={`0 0 ${dim} ${dim}`} shapeRendering="crispEdges">
      <rect width={dim} height={dim} fill="white" />
      <path d={path} fill="black" />
    </svg>
  );
}
