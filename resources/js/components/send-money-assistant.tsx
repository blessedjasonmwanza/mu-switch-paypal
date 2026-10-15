import { Link, router } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { Bot, Check, Loader2, Send, ShieldCheck, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import SendMoneyAssistantController from '@/actions/App/Http/Controllers/SendMoneyAssistantController';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import ai from '@/routes/ai';

interface Pending {
    id: string;
    tool: string;
    reason: string | null;
    arguments: Record<string, unknown>;
}

interface StartedTransfer {
    reference: string;
    status_label: string;
    sender_pays: { amount: string; currency: string };
    recipient_gets: { amount: string; currency: string };
    checkout_url: string | null;
}

interface Message {
    role: 'user' | 'assistant';
    text: string;
}

const EXAMPLES = [
    'Send K2,500 to my mum on 0977123456',
    'How much does $100 come to in Zambia?',
    'Where is my last transfer?',
];

function csrfToken(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}

/**
 * Chat with the Send Money agent. It quotes and checks transfers on its own;
 * starting one shows an approval card, and nothing moves until it's approved
 * here and paid on PayPal.
 */
export function SendMoneyAssistant({
    aiReady,
    canSend,
    onlyReload,
}: {
    aiReady: boolean;
    canSend: boolean;
    onlyReload: string[];
}) {
    const [messages, setMessages] = useState<Message[]>([]);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [pending, setPending] = useState<Pending[]>([]);
    const [started, setStarted] = useState<StartedTransfer | null>(null);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const endRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'nearest' });
    }, [messages, pending, started]);

    const call = async (url: string, body: Record<string, unknown>) => {
        setBusy(true);
        setError(null);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(body),
            });
            const json = await response.json();

            if (!response.ok) {
                setError(json.message ?? 'The assistant could not respond.');

                return;
            }

            const data = json.data;
            setConversationId(data.conversation_id);
            setPending(data.pending ?? []);

            if (data.reply) {
                setMessages((current) => [
                    ...current,
                    { role: 'assistant', text: data.reply },
                ]);
            }

            if (data.transfer) {
                setStarted(data.transfer);
                router.reload({ only: onlyReload });
            }
        } catch {
            setError('Could not reach the assistant.');
        } finally {
            setBusy(false);
        }
    };

    const send = (text: string) => {
        if (!text.trim() || busy) {
            return;
        }

        setMessages((current) => [...current, { role: 'user', text }]);
        setDraft('');
        void call(SendMoneyAssistantController.message.url(), {
            message: text,
            conversation_id: conversationId,
        });
    };

    const decide = (approval: Pending, choice: 'approve' | 'reject') => {
        setMessages((current) => [
            ...current,
            {
                role: 'user',
                text: choice === 'approve' ? 'Approved.' : 'Rejected.',
            },
        ]);
        void call(SendMoneyAssistantController.decide.url(), {
            conversation_id: conversationId,
            decisions: { [approval.id]: choice },
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(draft);
    };

    if (!canSend) {
        return (
            <p className="py-10 text-center text-sm text-neutral-500">
                Set up PayPal and a payout provider first (see above).
            </p>
        );
    }

    if (!aiReady) {
        return (
            <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-neutral-500">
                <Bot className="h-6 w-6 text-neutral-400" />
                Add an AI provider to send money by chatting.
                <Button asChild size="1" variant="soft">
                    <Link href={ai.index.url()}>Open AI &amp; Agents</Link>
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col" data-test="send-assistant">
            <div className="max-h-[26rem] min-h-[14rem] space-y-3 overflow-y-auto pr-1">
                {messages.length === 0 && (
                    <div className="space-y-2 pt-1">
                        <p className="text-xs text-neutral-500">
                            Tell the assistant who to pay and how much. It
                            quotes first, and every transfer waits for your
                            approval.
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {EXAMPLES.map((example) => (
                                <button
                                    key={example}
                                    type="button"
                                    onClick={() => send(example)}
                                    className="cursor-pointer rounded-full border border-sidebar-border/70 px-2.5 py-1 text-[11px] text-neutral-600 hover:bg-neutral-50 dark:border-sidebar-border dark:text-neutral-300 dark:hover:bg-neutral-900"
                                >
                                    {example}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {messages.map((message, index) => (
                    <div
                        key={index}
                        className={cn(
                            'flex',
                            message.role === 'user'
                                ? 'justify-end'
                                : 'justify-start',
                        )}
                    >
                        <p
                            className={cn(
                                'max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-line',
                                message.role === 'user'
                                    ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900'
                                    : 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100',
                            )}
                        >
                            {message.text}
                        </p>
                    </div>
                ))}

                {pending.map((approval) => (
                    <div
                        key={approval.id}
                        className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900/60 dark:bg-amber-950/30"
                        data-test="assistant-approval"
                    >
                        <p className="flex items-center gap-1.5 font-medium text-amber-900 dark:text-amber-200">
                            <ShieldCheck className="h-4 w-4" />
                            Approval needed
                        </p>
                        <p className="mt-1 text-xs text-amber-800 dark:text-amber-300/90">
                            {approval.reason ??
                                `The assistant wants to run ${approval.tool}.`}
                        </p>
                        <div className="mt-2.5 flex gap-2">
                            <Button
                                size="1"
                                onClick={() => decide(approval, 'approve')}
                                disabled={busy}
                                className="cursor-pointer gap-1"
                            >
                                <Check className="h-3 w-3" /> Approve
                            </Button>
                            <Button
                                size="1"
                                variant="soft"
                                color="gray"
                                onClick={() => decide(approval, 'reject')}
                                disabled={busy}
                                className="cursor-pointer gap-1"
                            >
                                <X className="h-3 w-3" /> Reject
                            </Button>
                        </div>
                    </div>
                ))}

                {started?.checkout_url && (
                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm dark:border-blue-900/60 dark:bg-blue-950/30">
                        <p className="font-medium text-blue-900 dark:text-blue-100">
                            {started.recipient_gets.currency}{' '}
                            {Number(
                                started.recipient_gets.amount,
                            ).toLocaleString('en-US', {
                                minimumFractionDigits: 2,
                            })}{' '}
                            ready to send
                        </p>
                        <p className="mt-0.5 text-xs text-blue-800 dark:text-blue-300/90">
                            The sender pays {started.sender_pays.currency}{' '}
                            {started.sender_pays.amount} on PayPal. The
                            recipient is paid as soon as PayPal confirms.
                        </p>
                        <Button
                            size="2"
                            style={{ width: '100%' }}
                            className="mt-2.5 cursor-pointer"
                            onClick={() =>
                                window.location.assign(started.checkout_url!)
                            }
                        >
                            Pay with PayPal
                        </Button>
                    </div>
                )}

                {busy && (
                    <p className="flex items-center gap-2 text-xs text-neutral-500">
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                        Thinking…
                    </p>
                )}
                {error && (
                    <p className="text-xs text-red-600 dark:text-red-400">
                        {error}
                    </p>
                )}
                <div ref={endRef} />
            </div>

            <form onSubmit={submit} className="mt-3 flex gap-2">
                <Input
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    placeholder="e.g. Send K2,500 to 0977123456"
                    aria-label="Message the assistant"
                    disabled={busy || pending.length > 0}
                />
                <Button
                    type="submit"
                    disabled={busy || !draft.trim() || pending.length > 0}
                    className="shrink-0 cursor-pointer"
                    aria-label="Send message"
                >
                    <Send className="h-4 w-4" />
                </Button>
            </form>
        </div>
    );
}
