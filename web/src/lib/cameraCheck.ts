/**
 * Camera check during a test (Sprint 5.1c): one small photo at the start and three at random moments.
 * Photos are 160×120 JPEG at low quality (about 5–15 KB each), sent straight to the server; nothing is kept in
 * the browser. If the camera cannot start, the test carries on and the assessor sees there are no photos.
 */
export function startCameraCheck(minutes: number, send: (photo: Blob) => void): () => void {
  let stream: MediaStream | null = null;
  const timers: ReturnType<typeof setTimeout>[] = [];
  const video = document.createElement('video');
  video.muted = true;
  video.playsInline = true;

  const snap = () => {
    if (!stream) return;
    const canvas = document.createElement('canvas');
    canvas.width = 160;
    canvas.height = 120;
    canvas.getContext('2d')?.drawImage(video, 0, 0, 160, 120);
    canvas.toBlob((b) => { if (b) send(b); }, 'image/jpeg', 0.5);
  };

  if (navigator.mediaDevices?.getUserMedia) {
    navigator.mediaDevices.getUserMedia({ video: { width: 160, height: 120, facingMode: 'user' }, audio: false })
      .then((s) => {
        stream = s;
        video.srcObject = s;
        void video.play().then(() => {
          timers.push(setTimeout(snap, 2000));
          const span = Math.max(1, minutes) * 60_000;
          for (let i = 0; i < 3; i++) timers.push(setTimeout(snap, 30_000 + Math.random() * (span - 60_000)));
        });
      })
      .catch(() => undefined);
  }
  return () => {
    timers.forEach(clearTimeout);
    stream?.getTracks().forEach((tr) => tr.stop());
  };
}
