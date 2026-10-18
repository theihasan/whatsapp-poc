import { Link } from '@inertiajs/react';
import {
    FileText,
    History,
    Inbox,
    LayoutGrid,
    Smartphone,
    Users,
    Workflow,
} from 'lucide-react';
import AutomationRuleController from '@/actions/App/Http/Controllers/Automations/AutomationRuleController';
import TemplateController from '@/actions/App/Http/Controllers/Templates/TemplateController';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import DeviceController from '@/actions/App/Http/Controllers/Devices/DeviceController';
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
import { activity, dashboard, inbox } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Inbox',
        href: inbox(),
        icon: Inbox,
    },
    {
        title: 'Contacts',
        href: ContactController.index(),
        icon: Users,
    },
    {
        title: 'Templates',
        href: TemplateController.index(),
        icon: FileText,
    },
    {
        title: 'Automations',
        href: AutomationRuleController.index(),
        icon: Workflow,
    },
    {
        title: 'Devices',
        href: DeviceController.index(),
        icon: Smartphone,
    },
    {
        title: 'Activity',
        href: activity(),
        icon: History,
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
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
