import React, { useEffect } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import {
  Bold,
  Italic,
  List,
  ListOrdered,
  Link as LinkIcon,
  RotateCcw,
  RotateCw,
  Heading1,
  Heading2,
  Quote,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export interface RichTextEditorProps {
  value: string;
  onChange: (html: string) => void;
  placeholder?: string;
  minHeight?: string | number;
  readOnly?: boolean;
  className?: string;
}

export function RichTextEditor({
  value,
  onChange,
  placeholder = 'Write content here...',
  minHeight = '140px',
  readOnly = false,
  className,
}: RichTextEditorProps) {
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: {
          levels: [1, 2, 3],
        },
      }),
      Placeholder.configure({
        placeholder,
      }),
      Link.configure({
        openOnClick: false,
        HTMLAttributes: {
          class: 'text-ovs-accent underline font-semibold hover:text-ovs-accent-hover',
        },
      }),
    ],
    content: value || '',
    editable: !readOnly,
    onUpdate: ({ editor }) => {
      onChange(editor.getHTML());
    },
    editorProps: {
      attributes: {
        class: cn(
          'focus:outline-none text-xs text-ovs-primary leading-relaxed p-3.5 min-h-[140px]',
          // Explicit Heading styling (matching OVS typography tokens)
          '[&_h1]:text-lg [&_h1]:font-extrabold [&_h1]:text-ovs-primary [&_h1]:mt-3 [&_h1]:mb-1 font-display',
          '[&_h2]:text-base [&_h2]:font-bold [&_h2]:text-ovs-primary [&_h2]:mt-2.5 [&_h2]:mb-1 font-display',
          '[&_h3]:text-sm [&_h3]:font-bold [&_h3]:text-ovs-primary [&_h3]:mt-2 [&_h3]:mb-1',
          // Paragraph styling
          '[&_p]:mb-2 [&_p:last-child]:mb-0',
          // List styling (bullet & ordered)
          '[&_ul]:list-disc [&_ul]:pl-5 [&_ul]:mb-2 [&_ul]:space-y-1',
          '[&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:mb-2 [&_ol]:space-y-1',
          '[&_li]:text-xs [&_li]:text-ovs-primary',
          // Anchor Link styling
          '[&_a]:text-ovs-accent [&_a]:font-semibold [&_a]:underline hover:[&_a]:text-ovs-accent-hover',
          // Blockquote & Code styling
          '[&_blockquote]:border-l-2 [&_blockquote]:border-ovs-accent [&_blockquote]:pl-3 [&_blockquote]:italic [&_blockquote]:my-2 [&_blockquote]:text-ovs-secondary',
          '[&_code]:bg-ovs-background [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:rounded [&_code]:font-mono [&_code]:text-[11px]'
        ),
      },
    },
  });

  // Sync external value updates
  useEffect(() => {
    if (editor && value !== editor.getHTML()) {
      editor.commands.setContent(value || '');
    }
  }, [value, editor]);

  if (!editor) return null;

  const setLink = () => {
    const previousUrl = editor.getAttributes('link').href;
    const url = window.prompt('URL', previousUrl);
    if (url === null) return;
    if (url === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
      return;
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
  };

  return (
    <div
      className={cn(
        'w-full bg-white border border-ovs-border-subtle rounded-2xl shadow-2xs overflow-hidden transition-all',
        'focus-within:border-ovs-accent focus-within:ring-2 focus-within:ring-ovs-accent/20',
        readOnly && 'opacity-70 bg-ovs-background cursor-not-allowed',
        className
      )}
    >
      {/* Toolbar */}
      {!readOnly && (
        <div className="flex flex-wrap items-center gap-1 p-1.5 bg-ovs-background border-b border-ovs-border-subtle">
          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().toggleBold().run()}
            className={cn('w-7 h-7', editor.isActive('bold') && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Bold"
          >
            <Bold className="w-3.5 h-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().toggleItalic().run()}
            className={cn('w-7 h-7', editor.isActive('italic') && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Italic"
          >
            <Italic className="w-3.5 h-3.5" />
          </Button>

          <div className="w-px h-4 bg-ovs-border-subtle mx-1" />

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}
            className={cn('w-7 h-7', editor.isActive('heading', { level: 1 }) && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Heading 1"
          >
            <Heading1 className="w-3.5 h-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
            className={cn('w-7 h-7', editor.isActive('heading', { level: 2 }) && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Heading 2"
          >
            <Heading2 className="w-3.5 h-3.5" />
          </Button>

          <div className="w-px h-4 bg-ovs-border-subtle mx-1" />

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().toggleBulletList().run()}
            className={cn('w-7 h-7', editor.isActive('bulletList') && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Bullet List"
          >
            <List className="w-3.5 h-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().toggleOrderedList().run()}
            className={cn('w-7 h-7', editor.isActive('orderedList') && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Numbered List"
          >
            <ListOrdered className="w-3.5 h-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().toggleBlockquote().run()}
            className={cn('w-7 h-7', editor.isActive('blockquote') && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Blockquote"
          >
            <Quote className="w-3.5 h-3.5" />
          </Button>

          <div className="w-px h-4 bg-ovs-border-subtle mx-1" />

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={setLink}
            className={cn('w-7 h-7', editor.isActive('link') && 'bg-ovs-accent/15 text-ovs-accent font-bold')}
            title="Add Link"
          >
            <LinkIcon className="w-3.5 h-3.5" />
          </Button>

          <div className="flex-1" />

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().undo().run()}
            disabled={!editor.can().undo()}
            className="w-7 h-7"
            title="Undo"
          >
            <RotateCcw className="w-3.5 h-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => editor.chain().focus().redo().run()}
            disabled={!editor.can().redo()}
            className="w-7 h-7"
            title="Redo"
          >
            <RotateCw className="w-3.5 h-3.5" />
          </Button>
        </div>
      )}

      {/* Editor Body Area */}
      <div style={{ minHeight: typeof minHeight === 'number' ? `${minHeight}px` : minHeight }}>
        <EditorContent editor={editor} />
      </div>
    </div>
  );
}
