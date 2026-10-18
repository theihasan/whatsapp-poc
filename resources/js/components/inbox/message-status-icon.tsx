import {
    AlertCircle,
    Check,
    CheckCheck,
    Clock,
    HelpCircle,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { MessageStatus } from '@/types';

const labels: Record<MessageStatus, string> = {
    queued: 'Queued',
    processing: 'Sending',
    gateway_accepted: 'Accepted by WhatsApp Web',
    sent: 'Sent',
    delivered: 'Delivered',
    read: 'Read',
    failed: 'Failed',
    unknown: 'Not confirmed',
    received: 'Received',
};

export function messageStatusLabel(status: MessageStatus): string {
    return labels[status];
}

/**
 * Only shows what the protocol actually confirmed: a single check means accepted/sent,
 * double means delivered, blue double means read.
 */
export default function MessageStatusIcon({
    status,
}: {
    status: MessageStatus;
}) {
    const className = 'size-3.5';
    const icon = {
        queued: <Clock className={className} />,
        processing: <Clock className={cn(className, 'animate-pulse')} />,
        gateway_accepted: <Check className={cn(className, 'opacity-60')} />,
        sent: <Check className={className} />,
        delivered: <CheckCheck className={className} />,
        read: <CheckCheck className={cn(className, 'text-sky-500')} />,
        failed: <AlertCircle className={cn(className, 'text-red-500')} />,
        unknown: <HelpCircle className={cn(className, 'text-amber-500')} />,
        received: null,
    }[status];

    return icon === null ? null : (
        <span
            title={labels[status]}
            aria-label={labels[status]}
            className="inline-flex"
        >
            {icon}
        </span>
    );
}
