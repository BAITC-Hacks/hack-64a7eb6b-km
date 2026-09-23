import { Head, Link } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit } from '@/routes/profile';

export default function AccessDenied() {
    return (
        <>
            <Head title="Доступ к рабочему пространству" />
            <div className="mx-auto flex w-full max-w-xl flex-col items-start gap-5 p-6 lg:p-10">
                <LockKeyhole className="size-8 text-muted-foreground" />
                <h1 className="text-2xl font-semibold">
                    Доступ к рабочему пространству не назначен
                </h1>
                <p className="text-sm leading-6 text-muted-foreground">
                    Обратитесь к администратору для назначения роли или
                    разрешений. Настройки вашего аккаунта остаются доступны.
                </p>
                <div className="flex flex-wrap gap-3">
                    <Button asChild>
                        <Link href={dashboard()}>Проверить доступ</Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={edit()}>Настройки аккаунта</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

AccessDenied.layout = {
    breadcrumbs: [{ title: 'Мой аккаунт', href: dashboard() }],
};
