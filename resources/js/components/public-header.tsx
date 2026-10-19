import { Link, usePage } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import { Github } from 'lucide-react';
import { dashboard, home, login, register } from '@/routes';

export const GITHUB_URL = 'https://github.com/moneyUnify/mu-switch';

/**
 * The top navigation on public pages: the landing page, privacy and terms.
 */
export function PublicHeader({
    githubUrl = GITHUB_URL,
    docsOnly = false,
}: {
    githubUrl?: string;
    /** The docs-only deployment has no accounts, so no sign-in links. */
    docsOnly?: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <header className="relative z-20 mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-6 sm:px-6">
            <Link href={home()} className="flex shrink-0 items-center gap-2.5">
                <img
                    src="/moneyunify-icon.png"
                    alt="MoneyUnify"
                    className="h-8 w-8 object-contain"
                />
                <span className="hidden text-lg font-semibold tracking-tight text-neutral-900 min-[400px]:inline dark:text-white">
                    MoneyUnify
                </span>
            </Link>
            <nav className="flex items-center gap-4 sm:gap-8">
                <span className="hidden sm:inline-flex">
                    <Button
                        asChild
                        size="2"
                        variant="ghost"
                        color="gray"
                        className="cursor-pointer"
                    >
                        <a href="/docs">Docs</a>
                    </Button>
                </span>
                <Button
                    asChild
                    size="2"
                    variant="ghost"
                    color="gray"
                    className="cursor-pointer"
                >
                    <a
                        href={githubUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="MoneyUnify on GitHub"
                    >
                        <Github className="h-4 w-4" aria-hidden />
                    </a>
                </Button>
                {!docsOnly && auth?.user ? (
                    <Button asChild size="2" className="cursor-pointer">
                        <Link href={dashboard()}>Dashboard</Link>
                    </Button>
                ) : !docsOnly ? (
                    <>
                        <Button
                            asChild
                            size="2"
                            variant="ghost"
                            color="gray"
                            className="cursor-pointer"
                        >
                            <Link href={login()}>Sign in</Link>
                        </Button>
                        <Button asChild size="2" className="cursor-pointer">
                            <Link href={register()}>Get Started</Link>
                        </Button>
                    </>
                ) : null}
            </nav>
        </header>
    );
}
