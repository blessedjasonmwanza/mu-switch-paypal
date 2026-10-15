import { Link } from '@inertiajs/react';
import {
    BookOpen,
    Sliders,
    LayoutGrid,
    ScrollText,
    Wallet,
    Percent,
    Bot,
    Landmark,
    ArrowLeftRight,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as aiIndex } from '@/routes/ai';
import feePolicy from '@/routes/fee-policy';
import logs from '@/routes/logs';
import payments from '@/routes/payments';
import providers from '@/routes/providers';
import rates from '@/routes/rates';
import { index as treasuryIndex } from '@/routes/treasury';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Providers',
        href: providers.index.url(),
        icon: Sliders,
    },
    {
        title: 'Payments',
        href: payments.index.url(),
        icon: Wallet,
    },
    {
        title: 'Logs',
        href: logs.index.url(),
        icon: ScrollText,
    },
    {
        title: 'Treasury',
        href: treasuryIndex.url(),
        icon: Landmark,
    },
    {
        title: 'Fee Policy',
        href: feePolicy.show.url(),
        icon: Percent,
    },
    {
        title: 'Exchange Rates',
        href: rates.index.url(),
        icon: ArrowLeftRight,
    },
    {
        title: 'AI & Agents',
        href: aiIndex.url(),
        icon: Bot,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Documentation',
        href: '/docs',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
