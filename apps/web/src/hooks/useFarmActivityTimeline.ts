import { useQuery } from '@tanstack/react-query';
import { farmActivityApi, type FarmActivityTimelineParams } from '../api/farmActivity';

export function useFarmActivityTimeline(params: FarmActivityTimelineParams | undefined, enabled = true) {
  return useQuery({
    queryKey: ['farm-activity', 'timeline', params],
    queryFn: () => farmActivityApi.getTimeline(params),
    enabled,
  });
}
