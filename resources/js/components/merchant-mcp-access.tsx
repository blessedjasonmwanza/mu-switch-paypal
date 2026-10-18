import { router } from '@inertiajs/react';
import { AlertDialog, Button, Flex } from '@radix-ui/themes';
import { Bot, Copy, KeyRound, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import MerchantController from '@/actions/App/Http/Controllers/MerchantController';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

export interface McpAccess {
    enabled: boolean;
    hint: string | null;
    issued_at: string | null;
}

function csrf(): string {
    return (
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? ''
    );
}

async function copyText(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copied.`);
    } catch {
        toast.error('Could not copy.');
    }
}

/**
 * A merchant's own access to MoneyUnify over MCP: the operator issues a key,
 * and the merchant connects its apps or an AI assistant with it. The key is
 * shown once; only its hint is kept.
 */
export function MerchantMcpAccess({
    merchantId,
    merchantName,
    access,
    endpoint,
    reloadOnly,
}: {
    merchantId: number;
    merchantName: string;
    access: McpAccess;
    endpoint: string;
    reloadOnly: string[];
}) {
    const [open, setOpen] = useState(false);
    const [key, setKey] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<'replace' | 'revoke' | null>(null);

    const send = async (method: 'POST' | 'DELETE') => {
        setBusy(true);
        setConfirm(null);

        try {
            const url =
                method === 'POST'
                    ? MerchantController.issueMcpKey.url(merchantId)
                    : MerchantController.revokeMcpKey.url(merchantId);
            const response = await fetch(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok) {
                toast.error(json.message ?? 'Something went wrong.');

                return;
            }

            setKey(method === 'POST' ? (json.data?.key ?? null) : null);
            toast.success(json.message);
            router.reload({ only: reloadOnly });
        } finally {
            setBusy(false);
        }
    };

    const config = JSON.stringify(
        {
            mcpServers: {
                [merchantName.toLowerCase().replace(/[^a-z0-9]+/g, '-')]: {
                    type: 'http',
                    url: endpoint,
                    headers: {
                        Authorization: `Bearer ${key ?? 'MERCHANT_MCP_KEY'}`,
                    },
                },
            },
        },
        null,
        2,
    );

    return (
        <>
            <Button
                size="1"
                variant="soft"
                color={access.enabled ? 'blue' : 'gray'}
                onClick={() => setOpen(true)}
                className="cursor-pointer gap-1"
                data-test="merchant-mcp"
            >
                <Bot className="h-3 w-3" /> AI &amp; apps
            </Button>

            <Sheet
                open={open}
                onOpenChange={(isOpen) => {
                    setOpen(isOpen);

                    if (!isOpen) {
                        setKey(null);
                    }
                }}
            >
                <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                    <SheetHeader>
                        <SheetTitle>{merchantName}: AI &amp; apps</SheetTitle>
                        <SheetDescription>
                            Give {merchantName} its own MCP key to build apps or
                            connect an AI assistant. It can charge customers,
                            follow payments and settlements, and manage its pay
                            links, and nothing else.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="space-y-4 px-4 pb-6 text-sm">
                        <div className="space-y-1.5">
                            <div className="text-xs font-medium">
                                Merchant MCP server
                            </div>
                            <div className="flex items-center gap-2">
                                <code className="min-w-0 flex-1 truncate rounded-md bg-neutral-100 px-2 py-1.5 text-xs dark:bg-neutral-800">
                                    {endpoint}
                                </code>
                                <Button
                                    size="1"
                                    variant="soft"
                                    color="gray"
                                    className="cursor-pointer"
                                    onClick={() => copyText(endpoint, 'URL')}
                                >
                                    <Copy className="h-3 w-3" />
                                </Button>
                            </div>
                        </div>

                        {key ? (
                            <div
                                className="space-y-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/30"
                                data-test="merchant-mcp-key"
                            >
                                <div className="text-xs font-semibold text-emerald-800 dark:text-emerald-300">
                                    Copy this key now. It won&apos;t be shown
                                    again.
                                </div>
                                <div className="flex items-center gap-2">
                                    <code className="min-w-0 flex-1 rounded-md bg-white px-2 py-1.5 text-xs break-all dark:bg-neutral-900">
                                        {key}
                                    </code>
                                    <Button
                                        size="1"
                                        variant="soft"
                                        className="cursor-pointer"
                                        onClick={() => copyText(key, 'Key')}
                                    >
                                        <Copy className="h-3 w-3" />
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <div className="flex items-center gap-2 rounded-lg border border-sidebar-border/70 p-3 text-xs dark:border-sidebar-border">
                                <KeyRound className="h-4 w-4 shrink-0 text-neutral-400" />
                                {access.enabled ? (
                                    <span>
                                        Key{' '}
                                        <code className="font-mono">
                                            {access.hint}
                                        </code>{' '}
                                        issued{' '}
                                        {access.issued_at
                                            ? new Date(
                                                  access.issued_at,
                                              ).toLocaleDateString()
                                            : ''}
                                        .
                                    </span>
                                ) : (
                                    <span className="text-neutral-500">
                                        No key yet: {merchantName} can&apos;t
                                        connect.
                                    </span>
                                )}
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <div className="text-xs font-medium">
                                MCP client config
                            </div>
                            <pre className="overflow-x-auto rounded-md bg-neutral-100 p-2 text-[11px] leading-relaxed dark:bg-neutral-800">
                                {config}
                            </pre>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                onClick={() =>
                                    access.enabled
                                        ? setConfirm('replace')
                                        : send('POST')
                                }
                                disabled={busy}
                                className="cursor-pointer gap-1.5"
                            >
                                {busy && (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                )}
                                {access.enabled ? 'Replace key' : 'Issue key'}
                            </Button>
                            {access.enabled && (
                                <Button
                                    variant="soft"
                                    color="red"
                                    onClick={() => setConfirm('revoke')}
                                    disabled={busy}
                                    className="cursor-pointer"
                                >
                                    Revoke
                                </Button>
                            )}
                        </div>
                    </div>
                </SheetContent>
            </Sheet>

            <AlertDialog.Root
                open={confirm !== null}
                onOpenChange={(isOpen) => !isOpen && setConfirm(null)}
            >
                <AlertDialog.Content maxWidth="440px">
                    <AlertDialog.Title>
                        {confirm === 'replace'
                            ? `Replace ${merchantName}'s key?`
                            : `Revoke ${merchantName}'s key?`}
                    </AlertDialog.Title>
                    <AlertDialog.Description size="2">
                        {confirm === 'replace'
                            ? 'The current key stops working straight away. Apps and assistants using it need the new key.'
                            : `${merchantName}'s apps and assistants lose access straight away. You can issue a new key later.`}
                    </AlertDialog.Description>
                    <Flex gap="3" mt="4" justify="end">
                        <AlertDialog.Cancel>
                            <Button
                                variant="soft"
                                color="gray"
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                        </AlertDialog.Cancel>
                        <Button
                            color={confirm === 'revoke' ? 'red' : undefined}
                            onClick={() =>
                                send(confirm === 'revoke' ? 'DELETE' : 'POST')
                            }
                            className="cursor-pointer"
                        >
                            {confirm === 'replace'
                                ? 'Replace key'
                                : 'Revoke key'}
                        </Button>
                    </Flex>
                </AlertDialog.Content>
            </AlertDialog.Root>
        </>
    );
}
