<script setup lang="ts">
import { CheckCircle2, Key, Plus, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Breadcrumb,
    BreadcrumbEllipsis,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSeparator,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import DemoBlock from './DemoBlock.vue';

const buttonVariantNames = [
    'default',
    'secondary',
    'outline',
    'ghost',
    'link',
    'destructive',
] as const;

const buttonSizeNames = ['sm', 'default', 'lg'] as const;
const iconSizeNames = ['icon-sm', 'icon', 'icon-lg'] as const;

const retainForever = ref(true);
const alsoPurgeTraces = ref(false);
const otpCode = ref('');
</script>

<template>
    <DemoBlock
        title="Button"
        description="Six variants, three text sizes and three icon sizes, plus the disabled and with-icon treatments"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Button
                v-for="variant in buttonVariantNames"
                :key="variant"
                :variant="variant"
            >
                {{ variant }}
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button v-for="size in buttonSizeNames" :key="size" :size="size">
                size={{ size }}
            </Button>
            <Button
                v-for="size in iconSizeNames"
                :key="size"
                :size="size"
                variant="outline"
                aria-label="New project"
            >
                <Plus />
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button>
                <Plus />
                New project
            </Button>
            <Button variant="outline">
                <Key />
                Create API key
            </Button>
            <Button disabled>Disabled</Button>
            <Button variant="outline" disabled>Disabled outline</Button>
            <Button disabled>
                <Spinner />
                Provisioning
            </Button>
        </div>
    </DemoBlock>

    <DemoBlock
        title="Alert"
        description="default and destructive, both with a leading icon"
    >
        <Alert>
            <CheckCircle2 />
            <AlertTitle>Ingest endpoint is live</AlertTitle>
            <AlertDescription>
                checkout-api received its first batch 12 seconds ago.
            </AlertDescription>
        </Alert>
        <Alert variant="destructive">
            <TriangleAlert />
            <AlertTitle>ClickHouse is unreachable</AlertTitle>
            <AlertDescription>
                Search is disabled until the connection recovers. Buffered rows
                are retained on disk.
            </AlertDescription>
        </Alert>
    </DemoBlock>

    <DemoBlock
        title="Badge"
        description="default, secondary, destructive, outline"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Badge>Production</Badge>
            <Badge variant="secondary">Staging</Badge>
            <Badge variant="destructive">Revoked</Badge>
            <Badge variant="outline">Read only</Badge>
            <Badge variant="secondary">
                <Key />
                blsk_live_…9f2c
            </Badge>
        </div>
    </DemoBlock>

    <DemoBlock title="Avatar" description="image with fallback initials">
        <div class="flex items-center gap-3">
            <Avatar>
                <AvatarImage src="" alt="Sam Vrablik" />
                <AvatarFallback>SV</AvatarFallback>
            </Avatar>
            <Avatar class="size-10">
                <AvatarFallback>AK</AvatarFallback>
            </Avatar>
            <Avatar class="size-12">
                <AvatarFallback>BI</AvatarFallback>
            </Avatar>
        </div>
    </DemoBlock>

    <DemoBlock
        title="Breadcrumb"
        description="with separator, ellipsis and current page"
    >
        <Breadcrumb>
            <BreadcrumbList>
                <BreadcrumbItem>
                    <BreadcrumbLink href="#components">Acme</BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    <BreadcrumbEllipsis />
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    <BreadcrumbLink href="#components">Projects</BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator />
                <BreadcrumbItem>
                    <BreadcrumbPage>checkout-api</BreadcrumbPage>
                </BreadcrumbItem>
            </BreadcrumbList>
        </Breadcrumb>
    </DemoBlock>

    <DemoBlock
        title="Card"
        description="header, action, content and footer slots"
    >
        <Card>
            <CardHeader>
                <CardTitle>checkout-api</CardTitle>
                <CardDescription>
                    OTLP over HTTP · 1.2M rows in the last 24 hours
                </CardDescription>
                <CardAction>
                    <Badge variant="secondary">Production</Badge>
                </CardAction>
            </CardHeader>
            <CardContent class="text-sm text-muted-foreground">
                Retention is set to 30 days. The oldest row currently stored was
                ingested on 27 July 2026.
            </CardContent>
            <CardFooter class="gap-2">
                <Button size="sm">Open logs</Button>
                <Button size="sm" variant="outline">Settings</Button>
            </CardFooter>
        </Card>
    </DemoBlock>

    <DemoBlock
        title="Checkbox + Label"
        description="checked, unchecked and disabled"
    >
        <div class="flex items-center gap-2">
            <Checkbox id="sg-retain" v-model="retainForever" />
            <Label for="sg-retain">Retain fatal logs forever</Label>
        </div>
        <div class="flex items-center gap-2">
            <Checkbox id="sg-traces" v-model="alsoPurgeTraces" />
            <Label for="sg-traces">Purge linked traces as well</Label>
        </div>
        <div class="flex items-center gap-2">
            <Checkbox id="sg-locked" disabled />
            <Label for="sg-locked">Managed by your plan</Label>
        </div>
    </DemoBlock>

    <DemoBlock
        title="Input + Label"
        description="default, placeholder, disabled and invalid"
    >
        <div class="grid gap-1.5">
            <Label for="sg-project">Project name</Label>
            <Input id="sg-project" model-value="checkout-api" />
        </div>
        <div class="grid gap-1.5">
            <Label for="sg-service">Service filter</Label>
            <Input id="sg-service" placeholder="All services" />
        </div>
        <div class="grid gap-1.5">
            <Label for="sg-endpoint">Ingest endpoint</Label>
            <Input
                id="sg-endpoint"
                model-value="https://bilis.example.com/api/v1/logs"
                disabled
            />
        </div>
        <div class="grid gap-1.5">
            <Label for="sg-invalid">Retention (days)</Label>
            <Input id="sg-invalid" model-value="0" aria-invalid="true" />
        </div>
    </DemoBlock>

    <DemoBlock
        title="Input OTP"
        description="the six digit two factor challenge"
    >
        <InputOTP v-model="otpCode" :maxlength="6">
            <InputOTPGroup>
                <InputOTPSlot :index="0" />
                <InputOTPSlot :index="1" />
                <InputOTPSlot :index="2" />
            </InputOTPGroup>
            <InputOTPSeparator />
            <InputOTPGroup>
                <InputOTPSlot :index="3" />
                <InputOTPSlot :index="4" />
                <InputOTPSlot :index="5" />
            </InputOTPGroup>
        </InputOTP>
    </DemoBlock>

    <DemoBlock title="Separator" description="horizontal and vertical">
        <div class="space-y-2 text-sm">
            <p>Team settings</p>
            <Separator />
            <p>Danger zone</p>
        </div>
        <div class="flex h-8 items-center gap-3 text-sm">
            <span>12,481 rows</span>
            <Separator orientation="vertical" />
            <span>4 services</span>
            <Separator orientation="vertical" />
            <span>last 15 minutes</span>
        </div>
    </DemoBlock>

    <DemoBlock
        title="Skeleton"
        description="the loading state used while a log page streams in"
    >
        <div class="space-y-2">
            <Skeleton class="h-4 w-2/3" />
            <Skeleton class="h-4 w-full" />
            <Skeleton class="h-4 w-4/5" />
            <Skeleton class="h-4 w-1/2" />
        </div>
    </DemoBlock>

    <DemoBlock
        title="Spinner"
        description="inline loading indicator, sized with utilities"
    >
        <div class="flex items-center gap-4">
            <Spinner />
            <Spinner class="size-6" />
            <Spinner class="size-8 text-primary" />
            <span class="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner />
                Tailing checkout-api…
            </span>
        </div>
    </DemoBlock>
</template>
