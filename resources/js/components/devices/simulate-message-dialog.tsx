import { Form } from '@inertiajs/react';
import { useState } from 'react';
import MockDeviceSimulationController from '@/actions/App/Http/Controllers/Devices/MockDeviceSimulationController';
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
import { Textarea } from '@/components/ui/textarea';
import type { Device } from '@/types';

export default function SimulateMessageDialog({ device }: { device: Device }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Simulate incoming
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Simulate an incoming message</DialogTitle>
                    <DialogDescription>
                        Mock provider only. Runs the same pipeline a real
                        WhatsApp message would.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...MockDeviceSimulationController.incomingMessage.form(
                        device.id,
                    )}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess={['body']}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="sim-name">
                                        Sender name
                                    </Label>
                                    <Input
                                        id="sim-name"
                                        name="name"
                                        placeholder="Alex Doe"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="sim-phone">Phone</Label>
                                    <Input
                                        id="sim-phone"
                                        name="phone_number"
                                        placeholder="+12025550123"
                                    />
                                    <InputError message={errors.phone_number} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="sim-group">
                                    Group name (optional)
                                </Label>
                                <Input
                                    id="sim-group"
                                    name="group_name"
                                    placeholder="Leave empty for a direct chat"
                                    maxLength={100}
                                />
                                <InputError message={errors.group_name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="sim-body">Message</Label>
                                <Textarea
                                    id="sim-body"
                                    name="body"
                                    required
                                    maxLength={4096}
                                    placeholder="Hi, I have a question about pricing"
                                />
                                <InputError message={errors.body} />
                            </div>
                            <DialogFooter>
                                <Button disabled={processing}>
                                    Send to inbox
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
