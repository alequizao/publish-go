<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\AuditLog;
use PublishGo\Models\Company;
use PublishGo\Models\Product;
use PublishGo\Models\ProductCategory;
use PublishGo\Models\ProductOption;
use PublishGo\Models\ProductOptionGroup;
use PublishGo\Services\PlanGate;

/**
 * Gestão do catálogo pelo estabelecimento (escopado por company_id):
 * categorias, produtos, estoque e complementos. Respeita os limites do plano.
 */
final class CatalogController extends Controller
{
    private function company(Request $request): array
    {
        $c = Company::find($this->companyId($request));
        if ($c === null) {
            throw HttpException::unauthorized();
        }
        return $c;
    }

    /* ───────── Limites/uso do plano (para o painel) ───────── */
    public function limits(Request $request): mixed
    {
        return PlanGate::snapshot($this->company($request));
    }

    /* ───────── Categorias ───────── */
    public function categories(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $rows = Database::select(
            'SELECT pc.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = pc.id) AS products_count
             FROM product_categories pc WHERE pc.company_id = :c
             ORDER BY pc.position ASC, pc.id ASC',
            ['c' => $companyId]
        );
        return array_map([$this, 'castCategory'], $rows);
    }

    public function storeCategory(Request $request): mixed
    {
        $company = $this->company($request);
        PlanGate::ensureCanCreate($company, 'categories');
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|max:120',
            'description' => 'string|max:255',
            'position' => 'integer',
            'is_active' => 'integer',
        ]);
        $id = ProductCategory::create([
            'company_id' => $company['id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'position' => (int) ($data['position'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);
        AuditLog::record((int) $company['id'], $this->userId($request), 'catalog.category.create', 'category', $id, $request->ip());
        Response::success($this->castCategory((array) ProductCategory::find($id, (int) $company['id'])), 201);
        return null;
    }

    public function updateCategory(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        if (ProductCategory::find($id, $companyId) === null) {
            throw HttpException::notFound('Categoria não encontrada.');
        }
        $data = Validator::validate($request->all(), [
            'name' => 'string|max:120',
            'description' => 'string|max:255',
            'position' => 'integer',
            'is_active' => 'integer',
        ]);
        if ($data !== []) {
            ProductCategory::update($id, $data, $companyId);
        }
        return $this->castCategory((array) ProductCategory::find($id, $companyId));
    }

    public function deleteCategory(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        ProductCategory::delete($id, $companyId);
        return ['deleted' => true];
    }

    /* ───────── Produtos ───────── */
    public function products(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $sql = 'SELECT p.*, pc.name AS category_name FROM products p
                LEFT JOIN product_categories pc ON pc.id = p.category_id
                WHERE p.company_id = :c';
        $params = ['c' => $companyId];
        if (($cat = $request->query('category_id')) !== null && $cat !== '') {
            $sql .= ' AND p.category_id = :cat';
            $params['cat'] = (int) $cat;
        }
        if (($s = $request->query('search')) !== null && $s !== '') {
            $sql .= ' AND (p.name LIKE :s OR p.sku LIKE :s)';
            $params['s'] = '%' . $s . '%';
        }
        $sql .= ' ORDER BY p.position ASC, p.id DESC';
        $rows = Database::select($sql, $params);
        return array_map([$this, 'castProduct'], $rows);
    }

    public function showProduct(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $product = Product::find((int) $request->param('id'), $companyId);
        if ($product === null) {
            throw HttpException::notFound('Produto não encontrado.');
        }
        $product = $this->castProduct($product);
        $product['option_groups'] = $this->optionGroupsOf((int) $product['id']);
        return $product;
    }

    public function storeProduct(Request $request): mixed
    {
        $company = $this->company($request);
        PlanGate::ensureCanCreate($company, 'products');
        $data = $this->validateProduct($request, true);
        if (!PlanGate::feature($company, 'allow_stock')) {
            $data['track_stock'] = 0;
        }
        $data['company_id'] = $company['id'];
        $id = Product::create($data);
        AuditLog::record((int) $company['id'], $this->userId($request), 'catalog.product.create', 'product', $id, $request->ip());
        Response::success($this->castProduct((array) Product::find($id, (int) $company['id'])), 201);
        return null;
    }

    public function updateProduct(Request $request): mixed
    {
        $company = $this->company($request);
        $companyId = (int) $company['id'];
        $id = (int) $request->param('id');
        if (Product::find($id, $companyId) === null) {
            throw HttpException::notFound('Produto não encontrado.');
        }
        $data = $this->validateProduct($request, false);
        if (isset($data['track_stock']) && !PlanGate::feature($company, 'allow_stock')) {
            $data['track_stock'] = 0;
        }
        if ($data !== []) {
            Product::update($id, $data, $companyId);
        }
        AuditLog::record($companyId, $this->userId($request), 'catalog.product.update', 'product', $id, $request->ip());
        return $this->castProduct((array) Product::find($id, $companyId));
    }

    public function deleteProduct(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $id = (int) $request->param('id');
        Product::delete($id, $companyId);
        return ['deleted' => true];
    }

    /* ───────── Complementos (grupos + opções) ───────── */
    public function storeGroup(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $productId = (int) $request->param('id');
        if (Product::find($productId, $companyId) === null) {
            throw HttpException::notFound('Produto não encontrado.');
        }
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|max:120',
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_required' => 'integer',
        ]);
        $id = ProductOptionGroup::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'name' => $data['name'],
            'min_select' => (int) ($data['min_select'] ?? 0),
            'max_select' => (int) ($data['max_select'] ?? 1),
            'is_required' => (int) ($data['is_required'] ?? 0),
        ]);
        Response::success(['id' => $id], 201);
        return null;
    }

    public function deleteGroup(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        ProductOptionGroup::delete((int) $request->param('groupId'), $companyId);
        return ['deleted' => true];
    }

    public function storeOption(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $groupId = (int) $request->param('groupId');
        if (ProductOptionGroup::find($groupId, $companyId) === null) {
            throw HttpException::notFound('Grupo não encontrado.');
        }
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|max:120',
            'price' => 'numeric',
        ]);
        $id = ProductOption::create([
            'group_id' => $groupId,
            'name' => $data['name'],
            'price' => (float) ($data['price'] ?? 0),
        ]);
        Response::success(['id' => $id], 201);
        return null;
    }

    public function deleteOption(Request $request): mixed
    {
        $companyId = $this->companyId($request);
        $optionId = (int) $request->param('optionId');
        // Garante o escopo via join com o grupo da empresa.
        $opt = Database::first(
            'SELECT o.id FROM product_options o
             JOIN product_option_groups g ON g.id = o.group_id
             WHERE o.id = :o AND g.company_id = :c LIMIT 1',
            ['o' => $optionId, 'c' => $companyId]
        );
        if ($opt !== null) {
            Database::execute('DELETE FROM product_options WHERE id = :o', ['o' => $optionId]);
        }
        return ['deleted' => true];
    }

    /* ───────── helpers ───────── */
    private function validateProduct(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ($creating ? 'required|' : '') . 'string|max:150',
            'description' => 'string|max:500',
            'category_id' => 'integer',
            'image_url' => 'string|max:255',
            'price' => 'numeric',
            'promo_price' => 'numeric',
            'sku' => 'string|max:60',
            'unit' => 'string|max:20',
            'track_stock' => 'integer',
            'stock_qty' => 'integer',
            'stock_alert' => 'integer',
            'is_active' => 'integer',
            'is_featured' => 'integer',
            'position' => 'integer',
        ];
        $data = Validator::validate($request->all(), $rules);
        // category_id vazio → null
        if (array_key_exists('category_id', $data)) {
            $data['category_id'] = $data['category_id'] !== '' && (int) $data['category_id'] > 0 ? (int) $data['category_id'] : null;
        }
        if (array_key_exists('promo_price', $data)) {
            $data['promo_price'] = $data['promo_price'] === '' || $data['promo_price'] === null ? null : (float) $data['promo_price'];
        }
        return $data;
    }

    private function optionGroupsOf(int $productId): array
    {
        $groups = Database::select(
            'SELECT * FROM product_option_groups WHERE product_id = :p ORDER BY position ASC, id ASC',
            ['p' => $productId]
        );
        foreach ($groups as &$g) {
            $g['id'] = (int) $g['id'];
            $g['min_select'] = (int) $g['min_select'];
            $g['max_select'] = (int) $g['max_select'];
            $g['is_required'] = (int) $g['is_required'] === 1;
            $g['options'] = array_map(static function ($o) {
                $o['id'] = (int) $o['id'];
                $o['price'] = (float) $o['price'];
                return $o;
            }, Database::select('SELECT * FROM product_options WHERE group_id = :g AND is_active = 1 ORDER BY position ASC, id ASC', ['g' => $g['id']]));
        }
        return $groups;
    }

    private function castCategory(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['company_id'] = (int) $c['company_id'];
        $c['position'] = (int) $c['position'];
        $c['is_active'] = (int) $c['is_active'] === 1;
        if (isset($c['products_count'])) {
            $c['products_count'] = (int) $c['products_count'];
        }
        return $c;
    }

    private function castProduct(array $p): array
    {
        foreach (['id', 'company_id', 'category_id', 'stock_qty', 'stock_alert', 'position'] as $k) {
            if (array_key_exists($k, $p)) {
                $p[$k] = $p[$k] === null ? null : (int) $p[$k];
            }
        }
        $p['price'] = (float) $p['price'];
        $p['promo_price'] = isset($p['promo_price']) && $p['promo_price'] !== null ? (float) $p['promo_price'] : null;
        foreach (['track_stock', 'is_active', 'is_featured'] as $k) {
            if (array_key_exists($k, $p)) {
                $p[$k] = (int) $p[$k] === 1;
            }
        }
        return $p;
    }
}
