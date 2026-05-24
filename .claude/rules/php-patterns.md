# Mini ERP — PHP Patterns

## Model Pattern
```php
class MyModel extends Model {
    use SoftDeletes; // only master data

    protected function casts(): array {
        return ['status' => MyEnum::class, 'date_field' => 'date'];
    }

    public static function generateCode(): string {
        $last = static::withTrashed()->max('id') ?? 0;
        return 'PFX-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
```

## Enum Pattern
```php
enum MyStatus: string {
    case Draft = 'draft';
    case Active = 'active';

    public function label(): string {
        return match($this) { 
            self::Draft => 'Nháp', 
            self::Active => 'Hoạt động' 
        };
    }
    
    public function color(): string {
        return match($this) { 
            self::Draft => 'gray', 
            self::Active => 'green' 
        };
    }
}
```

## Service Pattern (FSM + Transaction)
```php
class MyService {
    public function confirm(MyModel $record): void {
        if ($record->status !== MyStatus::Pending)
            throw new \RuntimeException('Chỉ có thể xác nhận khi ở trạng thái Chờ.');
        
        DB::transaction(function () use ($record) {
            // business logic
            $record->update(['status' => MyStatus::Confirmed]);
        });
    }
}
```

## Controller Pattern
```php
class MyController extends Controller {
    public function index(Request $request): Response {
        $query = MyModel::with(['relation'])->orderByDesc('id');
        // filters...
        return Inertia::render('Module/Index', [
            'items'   => $query->paginate(20)->through(fn($m) => [...]),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse {
        $data = $request->validate([...]);
        $model = MyModel::create([...$data, 'created_by' => auth()->id()]);
        return redirect()->route('module.items.show', $model)
            ->with('success', 'Đã tạo.');
    }
}
```

## Inertia DTO Rule
**NEVER pass raw Eloquent models** — always map via `->through(fn)` or explicit array.
`$invoice->amountPaid()` and `$invoice->amountDue()` are computed — include them in the DTO explicitly.
