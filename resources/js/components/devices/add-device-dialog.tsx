import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import DeviceController from '@/actions/App/Http/Controllers/Devices/DeviceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ProviderOption } from '@/types';

export default function AddDeviceDialog({
    providers,
    defaultProvider,
}: {
    providers: ProviderOption[];
    defaultProvider: string;
}) {
    const [open, setOpen] = useState(false);
    const [provider, setProvider] = useState(defaultProvider);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Add device
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add WhatsApp device</DialogTitle>
                    <DialogDescription>
                        Name the number you are linking. Pairing starts when you
                        press Connect.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...DeviceController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="device-name">Name</Label>
                                <Input
                                    id="device-name"
                                    name="name"
                                    required
                                    maxLength={100}
                                    placeholder="Support line"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="device-provider">
                                    Provider
                                </Label>
                                <input
                                    type="hidden"
                                    name="provider"
                                    value={provider}
                                />
                                <Select
                                    value={provider}
                                    onValueChange={setProvider}
                                >
                                    <SelectTrigger id="device-provider">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {providers.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {provider === 'unofficial_web' && (
                                    <p className="text-xs text-amber-700 dark:text-amber-400">
                                        Uses an unofficial WhatsApp Web client.
                                        Only link a dedicated, consenting test
                                        account — the number may be restricted
                                        by WhatsApp.
                                    </p>
                                )}
                                <InputError message={errors.provider} />
                            </div>

                            <DialogFooter>
                                <Button disabled={processing}>
                                    Add device
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
