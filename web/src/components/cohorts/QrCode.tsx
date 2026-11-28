import { useQuery } from '@tanstack/react-query';
import { Skeleton } from '../ui';

/** A QR code drawn as SVG. The generator (about 7 KB) is loaded only when a QR code is shown. */
export function QrCode({ text, label, size = 220 }: { text: string; label: string; size?: number }) {
  const matrix = useQuery({
    queryKey: ['qr', text],
    staleTime: Infinity,
    queryFn: async () => {
      const { default: qrcode } = await import('qrcode-generator');
      const qr = qrcode(0, 'M');
      qr.addData(text);
      qr.make();
      const n = qr.getModuleCount();
      let path = '';
      for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) path += `M${c + 4} ${r + 4}h1v1h-1z`;
      return { n: n + 8, path };
    },
  });
  if (!matrix.data) return <Skeleton className="rounded-xl" />;
  return (
    <svg role="img" aria-label={label} viewBox={`0 0 ${matrix.data.n} ${matrix.data.n}`} width={size} height={size} shapeRendering="crispEdges" className="rounded-xl bg-white">
      <rect width="100%" height="100%" fill="#fff" />
      <path d={matrix.data.path} fill="#090c1c" />
    </svg>
  );
}
