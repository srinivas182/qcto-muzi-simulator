import { useEffect, useRef, useState } from 'react';
import { Pencil } from 'lucide-react';
import { cx } from './cx';

/**
 * Click-to-edit text. Saves on blur or Enter (Ctrl/⌘+Enter in multi-line), Esc cancels.
 * When not editable it renders plain text, so the same layout serves drafts and published versions.
 */
export function Editable({
  value, onSave, editable, multiline, placeholder, className, label, minLength = 1,
}: {
  value: string;
  onSave: (next: string) => Promise<unknown> | void;
  editable: boolean;
  multiline?: boolean;
  placeholder?: string;
  className?: string;
  label: string;
  minLength?: number;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  const [saving, setSaving] = useState(false);
  const ref = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (!editing) return;
    const el = ref.current;
    if (el) {
      el.focus();
      el.setSelectionRange(el.value.length, el.value.length);
      el.style.height = 'auto';
      el.style.height = `${el.scrollHeight}px`;
    }
  }, [editing]);

  if (!editable) {
    return value ? <span className={className}>{value}</span> : null;
  }

  const commit = async () => {
    const next = draft.trim();
    if (next === value.trim() || next.length < minLength) {
      setDraft(value);
      setEditing(false);
      return;
    }
    setSaving(true);
    try {
      await onSave(next);
      setEditing(false);
    } finally {
      setSaving(false);
    }
  };

  if (editing) {
    return (
      <textarea
        ref={ref}
        aria-label={label}
        value={draft}
        disabled={saving}
        rows={1}
        onChange={(e) => {
          setDraft(e.target.value);
          e.target.style.height = 'auto';
          e.target.style.height = `${e.target.scrollHeight}px`;
        }}
        onBlur={() => void commit()}
        onKeyDown={(e) => {
          if (e.key === 'Escape') {
            setDraft(value);
            setEditing(false);
          } else if (e.key === 'Enter' && (!multiline || e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            void commit();
          }
        }}
        className={cx('w-full resize-none rounded-lg border border-brand bg-surface px-2 py-1 text-ink outline-none ring-4 ring-[var(--brand-ring)]', className, saving && 'opacity-60')}
      />
    );
  }

  return (
    <button
      type="button"
      aria-label={`${label}: ${value || placeholder}`}
      onClick={() => {
        setDraft(value);
        setEditing(true);
      }}
      className={cx('group -mx-2 flex w-[calc(100%+1rem)] items-start gap-2 rounded-lg px-2 py-1 text-left transition hover:bg-surface-2', className)}
    >
      <span className={cx('flex-1', !value && 'italic text-muted')}>{value || placeholder}</span>
      <Pencil className="mt-1 size-3.5 shrink-0 text-muted opacity-0 transition group-hover:opacity-100" aria-hidden />
    </button>
  );
}
