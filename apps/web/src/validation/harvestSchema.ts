import { z } from 'zod';

export const harvestLineSchema = z.object({
  inventory_item_id: z.string().min(1, 'Item is required'),
  store_id: z.string().min(1, 'Store is required'),
  quantity: z.string().refine((val) => {
    const num = parseFloat(val);
    return !isNaN(num) && num > 0;
  }, 'Quantity must be greater than 0'),
  uom: z.string().optional().nullable(),
  notes: z.string().optional().nullable(),
});

export const harvestSchema = z.object({
  crop_cycle_id: z.string().min(1, 'Crop cycle is required'),
  project_id: z.string().min(1, 'Project is required'),
  harvest_date: z.string().min(1, 'Harvest date is required'),
  harvest_no: z.string().optional().nullable(),
  notes: z.string().optional().nullable(),
  lines: z.array(harvestLineSchema).min(1, 'At least one harvest line is required'),
});

export type HarvestFormData = z.infer<typeof harvestSchema>;
