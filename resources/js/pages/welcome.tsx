import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
import { usePermissions } from '@/hooks/use-permissions';

export default function Welcome() {
    const { auth } = usePage().props;
    const { can } = usePermissions();
    return (
        <>
            <Head title="Главная" />
            <main className="relative flex min-h-svh items-center justify-center bg-background px-6 text-foreground">
                <div className="w-full max-w-4xl">
                    <header className="absolute inset-x-6 top-12 mx-auto flex max-w-4xl items-center justify-between">
                        <span className="text-lg font-semibold tracking-tight">
                            Hackalem
                        </span>
                        <Button variant="outline" asChild>
                            <Link href={auth.user ? dashboard() : login()}>
                                {auth.user
                                    ? can('workspace.view')
                                        ? 'Открыть workspace'
                                        : 'Мой аккаунт'
                                    : 'Войти'}
                            </Link>
                        </Button>
                    </header>
                    <h1 className="text-center text-[clamp(2.5rem,10vw,7rem)] leading-none font-semibold tracking-tighter">
                        HACKALEM.AI
                    </h1>
                </div>
            </main>
        </>
    );
}
