import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { CircleAlert, CircleCheck } from 'lucide-react';

interface ToastItem {
  id: number;
  tone: 'success' | 'error';
  text: string;
}
const Ctx = createContext<(text: string, tone?: ToastItem['tone']) => void>(() => {});

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const push = useCallback((text: string, tone: ToastItem['tone'] = 'success') => {
    const id = Date.now() + Math.random();
    setItems((list) => [...list, { id, tone, text }]);
    setTimeout(() => setItems((list) => list.filter((i) => i.id !== id)), 4000);
  }, []);

  return (
    <Ctx.Provider value={push}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4">
        {items.map((i) => (
          <div
            key={i.id}
            className="sw-in pointer-events-auto flex items-center gap-2.5 rounded-xl bg-[#12152B] px-4 py-3 text-sm font-medium text-white shadow-xl dark:bg-[#ECEEF8] dark:text-[#12152B]"
          >
            {i.tone === 'success' ? <CircleCheck className="size-4 text-[#5FD3B1]" /> : <CircleAlert className="size-4 text-[#FF9A9E]" />}
            {i.text}
          </div>
        ))}
      </div>
    </Ctx.Provider>
  );
}

export const useToast = () => useContext(Ctx);
