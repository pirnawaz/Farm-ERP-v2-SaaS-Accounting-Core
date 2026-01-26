import { z } from 'zod';

export const settlementPreviewSchema = z.object({
  projectId: z.string().min(1, 'Project is required'),
  upToDate: z.string().min(1, 'Up to date is required'),
});

export const settlementPostSchema = z.object({
  projectId: z.string().min(1, 'Project is required'),
  postingDate: z.string().min(1, 'Posting date is required'),
  upToDate: z.string().min(1, 'Up to date is required'),
  applyAdvanceOffset: z.boolean().optional(),
  advanceOffsetAmount: z.number().optional().nullable(),
});

export type SettlementPreviewData = z.infer<typeof settlementPreviewSchema>;
export type SettlementPostData = z.infer<typeof settlementPostSchema>;
