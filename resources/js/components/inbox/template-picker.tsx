import { FileText } from 'lucide-react';
import { useState } from 'react';
import TemplateController from '@/actions/App/Http/Controllers/Templates/TemplateController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';

type RenderedTemplate = {
    id: number;
    name: string;
    category: string | null;
    rendered: string;
};

export default function TemplatePicker({
    conversationId,
    onPick,
    disabled,
}: {
    conversationId: string;
    onPick: (text: string) => void;
    disabled: boolean;
}) {
    const [templates, setTemplates] = useState<RenderedTemplate[] | null>(null);
    const [error, setError] = useState(false);

    const load = async () => {
        setError(false);

        try {
            const response = await fetch(
                TemplateController.forConversation.url(conversationId),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            setTemplates(
                ((await response.json()) as { templates: RenderedTemplate[] })
                    .templates,
            );
        } catch {
            setError(true);
        }
    };

    return (
        <DropdownMenu onOpenChange={(open) => open && void load()}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Insert template"
                    disabled={disabled}
                >
                    <FileText />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-80 w-72 overflow-y-auto"
            >
                <DropdownMenuLabel>Templates</DropdownMenuLabel>
                {error && (
                    <p className="px-2 py-1.5 text-sm text-red-600">
                        Could not load templates.
                    </p>
                )}
                {templates === null && !error && (
                    <div className="flex justify-center p-3">
                        <Spinner />
                    </div>
                )}
                {templates?.length === 0 && (
                    <p className="px-2 py-1.5 text-sm text-muted-foreground">
                        No active templates.
                    </p>
                )}
                {templates?.map((template) => (
                    <DropdownMenuItem
                        key={template.id}
                        onSelect={() => onPick(template.rendered)}
                        className="flex-col items-start"
                    >
                        <span className="font-medium">{template.name}</span>
                        <span className="line-clamp-2 text-xs text-muted-foreground">
                            {template.rendered}
                        </span>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
