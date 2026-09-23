import { Link } from '@inertiajs/react';
import { LayoutGrid, Map } from 'lucide-react';
import AppLogo from '@/components/app-logo';
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
import { dashboard, home } from '@/routes';
import { usePermissions } from '@/hooks/use-permissions';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Город и сценарии',
        href: dashboard(),
        icon: LayoutGrid,
    },
    { title: 'Карта', href: home(), icon: Map },
];

export function AppSidebar() {
    const { can } = usePermissions();

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
                <NavMain items={can('workspace.view') ? mainNavItems : []} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
