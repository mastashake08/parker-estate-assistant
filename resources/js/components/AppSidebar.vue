<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlarmClock,
    AudioLines,
    BookOpen,
    FolderGit2,
    LayoutGrid,
    MessageSquare,
    Plug,
    Sparkles,
    Wrench,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
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
import { index as audio } from '@/routes/audio';
import { index as chat } from '@/routes/chat';
import { index as mcpServers } from '@/routes/mcp-servers';
import { index as routines } from '@/routes/routines';
import { index as skills } from '@/routes/skills';
import { index as tools } from '@/routes/tools';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Chat',
        href: chat(),
        icon: MessageSquare,
    },
    {
        title: 'Audio studio',
        href: audio(),
        icon: AudioLines,
    },
    {
        title: 'MCP servers',
        href: mcpServers(),
        icon: Plug,
    },
    {
        title: 'Tools',
        href: tools(),
        icon: Wrench,
    },
    {
        title: 'Skills',
        href: skills(),
        icon: Sparkles,
    },
    {
        title: 'Routines',
        href: routines(),
        icon: AlarmClock,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/mastashake08/parker-estate-assistant',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
