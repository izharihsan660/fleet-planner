import ApplicationLogo from '@/Components/ApplicationLogo';
import { Card, CardContent } from '@/Components/ui/card';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-muted/40 px-4 pt-6 sm:justify-center sm:pt-0">
            <div>
                <Link href="/">
                    <ApplicationLogo className="h-16 w-16 fill-current text-muted-foreground" />
                </Link>
            </div>

            <Card className="mt-6 w-full max-w-md">
                <CardContent>{children}</CardContent>
            </Card>
        </div>
    );
}
