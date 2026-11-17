<?php
declare(strict_types=1);

// Categoria do diagrama de classes (alagamento, erosão, queda de talude).
final class Categoria
{
    public static function listar(): array
    {
        return Database::conexao()->query('SELECT id, slug, nome, icone, cor FROM categoria ORDER BY id')->fetchAll();
    }

    public static function idPorSlug(string $slug): ?int
    {
        $st = Database::conexao()->prepare('SELECT id FROM categoria WHERE slug = ?');
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
