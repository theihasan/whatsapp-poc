import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

/**
 * WhatsApp profile picture when one is stored, otherwise initials. Pictures load from the
 * authenticated app route, never from WhatsApp's CDN.
 */
export default function ContactAvatar({
    name,
    avatarUrl,
    className,
}: {
    name: string;
    avatarUrl: string | null | undefined;
    className?: string;
}) {
    const getInitials = useInitials();
    const initials = /^[+\d]/.test(name) ? '#' : getInitials(name);

    return (
        <Avatar className={cn('size-9 shrink-0', className)}>
            {avatarUrl && <AvatarImage src={avatarUrl} alt="" />}
            <AvatarFallback className="bg-muted text-xs font-medium text-muted-foreground">
                {initials}
            </AvatarFallback>
        </Avatar>
    );
}
