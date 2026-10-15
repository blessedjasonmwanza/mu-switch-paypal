import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AiSettingsController from '@/actions/App/Http/Controllers/Settings/AiSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/ai-settings';
import { index as providersIndex } from '@/routes/providers';

type KeySource = 'dashboard' | 'env' | null;

interface ProviderOption {
    value: string;
    label: string;
    envKey: string;
}

interface Props {
    providers: ProviderOption[];
    settings: {
        provider: string | null;
        model: string | null;
        maskedKey: string | null;
    };
    active: {
        provider: string;
        model: string | null;
        keySource: KeySource;
    };
    paypalMcp: {
        source: KeySource;
        provider: string | null;
        environment: string | null;
    };
}

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const keySourceLabel: Record<'dashboard' | 'env', string> = {
    dashboard: 'saved in the dashboard',
    env: 'from .env',
};

export default function AiSettings({
    providers,
    settings,
    active,
    paypalMcp,
}: Props) {
    const form = useForm({
        provider: settings.provider ?? '',
        model: settings.model ?? '',
        api_key: '',
    });

    const selected = providers.find((p) => p.value === form.data.provider);
    const activeLabel =
        providers.find((p) => p.value === active.provider)?.label ??
        active.provider;
    // A saved key only applies while its own provider stays selected.
    const keepsSavedKey =
        settings.maskedKey !== null && form.data.provider === settings.provider;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            provider: data.provider || null,
        }));
        form.put(AiSettingsController.update.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset('api_key'),
        });
    };

    return (
        <>
            <Head title="AI & Agents settings" />

            <h1 className="sr-only">AI & Agents settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="AI provider"
                    description="Choose the model your agents use. Anything left blank falls back to the server's .env settings."
                />

                <div
                    className="rounded-xl border border-border p-4 text-sm"
                    data-test="ai-active-credentials"
                >
                    <p className="font-medium">Agents currently use</p>
                    <p className="mt-1 text-muted-foreground">
                        {activeLabel} ·{' '}
                        {active.model ?? 'the provider’s default model'} ·{' '}
                        {active.keySource ? (
                            <>API key {keySourceLabel[active.keySource]}</>
                        ) : (
                            <span className="text-red-500">no API key set</span>
                        )}
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="provider">Provider</Label>
                        <select
                            id="provider"
                            value={form.data.provider}
                            onChange={(e) =>
                                form.setData('provider', e.target.value)
                            }
                            className={selectClassName}
                        >
                            <option value="">Use .env defaults</option>
                            {providers.map((provider) => (
                                <option
                                    key={provider.value}
                                    value={provider.value}
                                >
                                    {provider.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.provider} />
                    </div>

                    {selected && (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="model">Model (optional)</Label>
                                <Input
                                    id="model"
                                    value={form.data.model}
                                    onChange={(e) =>
                                        form.setData('model', e.target.value)
                                    }
                                    placeholder="Provider default"
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.model} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="api_key">API key</Label>
                                <Input
                                    id="api_key"
                                    type="password"
                                    value={form.data.api_key}
                                    onChange={(e) =>
                                        form.setData('api_key', e.target.value)
                                    }
                                    placeholder={
                                        keepsSavedKey
                                            ? `Saved (${settings.maskedKey}). Leave blank to keep.`
                                            : `Leave blank to use ${selected.envKey} from .env`
                                    }
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.api_key} />
                                <p className="text-xs text-muted-foreground">
                                    Keys are encrypted at rest and never shown
                                    again after saving.
                                </p>
                            </div>
                        </>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            disabled={form.processing}
                            data-test="save-ai-settings-button"
                        >
                            Save
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    AiSettingsController.test.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Test connection
                        </Button>
                        {settings.maskedKey && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        AiSettingsController.destroyKey.url(),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Remove saved key
                            </Button>
                        )}
                    </div>
                </form>
            </div>

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="PayPal MCP"
                    description="Lets your agents use PayPal’s tools, such as invoices and orders."
                />

                <p className="text-sm text-muted-foreground">
                    {paypalMcp.source === 'dashboard' && (
                        <>
                            Connected through your PayPal provider “
                            {paypalMcp.provider}” ({paypalMcp.environment}).
                            Access tokens refresh automatically.
                        </>
                    )}
                    {paypalMcp.source === 'env' && (
                        <>
                            Using MCP_PAYPAL_URL and MCP_PAYPAL_TOKEN from .env.
                        </>
                    )}
                    {paypalMcp.source === null && (
                        <>
                            Not connected. Add an active PayPal provider on the{' '}
                            <Link
                                href={providersIndex()}
                                className="text-foreground underline underline-offset-4"
                            >
                                Providers
                            </Link>{' '}
                            page, or set MCP_PAYPAL_TOKEN in .env.
                        </>
                    )}
                </p>
            </div>
        </>
    );
}

AiSettings.layout = {
    breadcrumbs: [
        {
            title: 'AI & Agents settings',
            href: edit(),
        },
    ],
};
