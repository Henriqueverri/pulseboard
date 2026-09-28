<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Plausible catalog for a small home-office / tech accessories store (BRL).
     *
     * @var list<array{0: string, 1: float, 2: float}>
     */
    public const CATALOG = [
        ['Mouse sem fio', 79.90, 149.90],
        ['Teclado mecânico', 249.90, 599.90],
        ['Headset USB', 129.90, 399.90],
        ['Webcam Full HD', 179.90, 449.90],
        ['Suporte para notebook', 69.90, 189.90],
        ['Hub USB-C', 99.90, 289.90],
        ['Mousepad XL', 39.90, 99.90],
        ['Cabo USB-C 2m', 29.90, 69.90],
        ['Carregador 65W', 149.90, 279.90],
        ['Monitor 24"', 799.90, 1299.90],
        ['Luminária de mesa LED', 89.90, 219.90],
        ['Cadeira ergonômica', 899.90, 2199.90],
        ['Mesa regulável', 1499.90, 3299.90],
        ['SSD externo 1TB', 399.90, 799.90],
        ['Microfone condensador', 199.90, 699.90],
        ['Apoio de punho', 34.90, 89.90],
        ['Filtro de linha', 49.90, 129.90],
        ['Organizador de cabos', 19.90, 59.90],
        ['Caixa de som bluetooth', 149.90, 499.90],
        ['Ring light', 79.90, 199.90],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$name, $min, $max] = fake()->randomElement(self::CATALOG);

        return [
            'organization_id' => Organization::factory(),
            'name' => $name.' '.fake()->randomElement(['Pro', 'Plus', 'Lite', 'Max', 'Basic', 'X']),
            'sku' => fake()->unique()->bothify('PB-####-??'),
            'price' => fake()->randomFloat(2, $min, $max),
            'status' => ProductStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Inactive]);
    }
}
