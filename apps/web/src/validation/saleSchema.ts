import { z } from 'zod';

export const saleLineSchema = z.object({
  inventory_item_id: z.string().min(1, 'Item is required'),
  store_id: z.string().min(1, 'Store is required'),
  quantity: z.string().refine((val) => {
    const num = parseFloat(val);
    return !isNaN(num) && num > 0;
  }, 'Quantity must be greater than 0'),
  unit_price: z.string().refine((val) => {
    const num = parseFloat(val);
    return !isNaN(num) && num >= 0;
  }, 'Unit price must be a valid number'),
  uom: z.string().optional().nullable(),
});

export const saleSchema = z.object({
  buyer_party_id: z.string().min(1, 'Buyer party is required'),
  project_id: z.string().optional().nullable(),
  crop_cycle_id: z.string().optional().nullable(),
  amount: z.string().optional(),
  posting_date: z.string().min(1, 'Posting date is required'),
  sale_no: z.string().optional().nullable(),
  sale_date: z.string().optional().nullable(),
  due_date: z.string().optional().nullable(),
  notes: z.string().optional().nullable(),
  sale_lines: z.array(saleLineSchema).min(1, 'At least one sale line is required'),
});

export type SaleFormData = z.infer<typeof saleSchema>;
