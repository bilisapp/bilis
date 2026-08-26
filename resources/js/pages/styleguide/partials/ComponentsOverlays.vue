<script setup lang="ts">
import {
    ChevronsUpDown,
    Copy,
    Ellipsis,
    ScrollText,
    Settings2,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NavigationMenu,
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    NavigationMenuTrigger,
} from '@/components/ui/navigation-menu';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import DemoBlock from './DemoBlock.vue';

const environment = ref('production');
const showResourceAttributes = ref(true);
</script>

<template>
    <DemoBlock
        title="Collapsible"
        description="used for the expanded attribute panel on a log row"
    >
        <Collapsible v-slot="{ open }" class="rounded-md border">
            <CollapsibleTrigger
                class="flex w-full items-center justify-between px-3 py-2 text-sm font-medium"
            >
                Resource attributes
                <ChevronsUpDown class="size-4 text-muted-foreground" />
            </CollapsibleTrigger>
            <CollapsibleContent
                class="space-y-1 border-t px-3 py-2 font-mono text-xs text-muted-foreground"
            >
                <p>deployment.environment = production</p>
                <p>host.name = ingest-worker-03</p>
                <p>service.version = 0.4.1</p>
            </CollapsibleContent>
            <p class="sr-only">{{ open ? 'Expanded' : 'Collapsed' }}</p>
        </Collapsible>
    </DemoBlock>

    <DemoBlock title="Dialog" description="the confirm-destructive pattern">
        <Dialog>
            <DialogTrigger as-child>
                <Button variant="destructive">
                    <Trash2 />
                    Revoke API key
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Revoke this API key?</DialogTitle>
                    <DialogDescription>
                        Any exporter still using blsk_live_…9f2c will start
                        receiving 401 responses immediately. This cannot be
                        undone.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-1.5">
                    <Label for="sg-confirm">
                        Type the key name to confirm
                    </Label>
                    <Input id="sg-confirm" placeholder="checkout-api-prod" />
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button variant="destructive">Revoke key</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </DemoBlock>

    <DemoBlock
        title="Dropdown menu"
        description="items, checkbox items, a submenu, shortcuts and a destructive item"
    >
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="outline" size="icon" aria-label="Row actions">
                    <Ellipsis />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-56">
                <DropdownMenuLabel>checkout-api</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                    <DropdownMenuItem>
                        <ScrollText />
                        Open in logs
                        <DropdownMenuShortcut>⌘L</DropdownMenuShortcut>
                    </DropdownMenuItem>
                    <DropdownMenuItem>
                        <Copy />
                        Copy trace ID
                        <DropdownMenuShortcut>⌘C</DropdownMenuShortcut>
                    </DropdownMenuItem>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuCheckboxItem v-model="showResourceAttributes">
                    Show resource attributes
                </DropdownMenuCheckboxItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>
                        <Settings2 />
                        Retention
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuItem>7 days</DropdownMenuItem>
                        <DropdownMenuItem>30 days</DropdownMenuItem>
                        <DropdownMenuItem>90 days</DropdownMenuItem>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive">
                    <Trash2 />
                    Delete project
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </DemoBlock>

    <DemoBlock
        title="Navigation menu"
        description="a horizontal menu with a viewport panel"
    >
        <NavigationMenu>
            <NavigationMenuList>
                <NavigationMenuItem>
                    <NavigationMenuTrigger>Projects</NavigationMenuTrigger>
                    <NavigationMenuContent>
                        <ul class="grid w-64 gap-1 p-2">
                            <li>
                                <NavigationMenuLink href="#components">
                                    <span class="text-sm font-medium">
                                        checkout-api
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        1.2M rows in 24h
                                    </span>
                                </NavigationMenuLink>
                            </li>
                            <li>
                                <NavigationMenuLink href="#components">
                                    <span class="text-sm font-medium">
                                        bilis-ingest
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        410k rows in 24h
                                    </span>
                                </NavigationMenuLink>
                            </li>
                        </ul>
                    </NavigationMenuContent>
                </NavigationMenuItem>
                <NavigationMenuItem>
                    <NavigationMenuTrigger>Docs</NavigationMenuTrigger>
                    <NavigationMenuContent>
                        <ul class="grid w-64 gap-1 p-2">
                            <li>
                                <NavigationMenuLink href="#components">
                                    <span class="text-sm font-medium">
                                        OTLP exporter setup
                                    </span>
                                </NavigationMenuLink>
                            </li>
                            <li>
                                <NavigationMenuLink href="#components">
                                    <span class="text-sm font-medium">
                                        Self-hosting Bilis
                                    </span>
                                </NavigationMenuLink>
                            </li>
                        </ul>
                    </NavigationMenuContent>
                </NavigationMenuItem>
            </NavigationMenuList>
        </NavigationMenu>
    </DemoBlock>

    <DemoBlock
        title="Select"
        description="grouped items with a label and separator"
    >
        <div class="grid max-w-xs gap-1.5">
            <Label for="sg-environment">Environment</Label>
            <Select v-model="environment">
                <SelectTrigger id="sg-environment">
                    <SelectValue placeholder="All environments" />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectLabel>Deployed</SelectLabel>
                        <SelectItem value="production">Production</SelectItem>
                        <SelectItem value="staging">Staging</SelectItem>
                    </SelectGroup>
                    <SelectSeparator />
                    <SelectGroup>
                        <SelectLabel>Local</SelectLabel>
                        <SelectItem value="development">Development</SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>
    </DemoBlock>

    <DemoBlock
        title="Sheet"
        description="the side panel used for filters and details"
    >
        <Sheet>
            <SheetTrigger as-child>
                <Button variant="outline">
                    <Settings2 />
                    Open filters
                </Button>
            </SheetTrigger>
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>Log filters</SheetTitle>
                    <SheetDescription>
                        Narrow the current window down to a single service.
                    </SheetDescription>
                </SheetHeader>
                <div class="grid gap-3 px-4">
                    <div class="grid gap-1.5">
                        <Label for="sg-sheet-service">Service</Label>
                        <Input
                            id="sg-sheet-service"
                            placeholder="checkout-api"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="sg-sheet-trace">Trace ID</Label>
                        <Input id="sg-sheet-trace" placeholder="4f2a9c1e…" />
                    </div>
                </div>
                <SheetFooter>
                    <Button>Apply filters</Button>
                    <SheetClose as-child>
                        <Button variant="outline">Cancel</Button>
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    </DemoBlock>

    <DemoBlock
        title="Sonner (toast)"
        description="the Toaster lives in the app layout; these buttons only fire toasts"
    >
        <div class="flex flex-wrap gap-2">
            <Button
                variant="outline"
                @click="toast.success('API key created.')"
            >
                Success toast
            </Button>
            <Button
                variant="outline"
                @click="toast.info('Live tail paused after 5 minutes idle.')"
            >
                Info toast
            </Button>
            <Button
                variant="outline"
                @click="toast.warning('Ingest is throttled: queue depth 4096.')"
            >
                Warning toast
            </Button>
            <Button
                variant="outline"
                @click="toast.error('Could not reach ClickHouse.')"
            >
                Error toast
            </Button>
        </div>
    </DemoBlock>

    <DemoBlock
        title="Tooltip"
        description="requires a TooltipProvider ancestor"
    >
        <TooltipProvider>
            <div class="flex flex-wrap gap-2">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button variant="outline">Hover me</Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        Rows are stored in UTC and rendered in your local zone.
                    </TooltipContent>
                </Tooltip>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button variant="outline" size="icon" aria-label="Copy">
                            <Copy />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent side="right">
                        Copy trace ID
                    </TooltipContent>
                </Tooltip>
            </div>
        </TooltipProvider>
    </DemoBlock>

    <DemoBlock title="Sidebar" description="not demonstrated inline">
        <p class="text-sm text-muted-foreground">
            The sidebar family is skipped here on purpose. Its components read
            from the SidebarProvider context that AppShell already mounts around
            this page, and a second Sidebar rendered inline would fight the real
            navigation rail for that context (and for the collapsed/expanded
            cookie). The live example is the rail on the left of this page — see
            AppSidebar.vue for how the pieces fit together.
        </p>
    </DemoBlock>
</template>
