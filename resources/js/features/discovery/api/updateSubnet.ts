import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Subnet, SubnetPlacement } from '../../../types';
import { subnetKeys } from './getSubnets';
import { candidateKeys } from './getCandidates';

export interface UpdateSubnetInput {
    id: number;
    enabled?: boolean;
    label?: string | null;
    scan_interval_s?: number;
    cidr?: string;
    map_id?: number | null;
    placement?: SubnetPlacement;
}

export function useUpdateSubnet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, ...patch }: UpdateSubnetInput): Promise<Subnet> => {
            const { data } = await apiClient.patch<{ data: Subnet }>(`/subnets/${id}`, patch);
            return data.data;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: subnetKeys.all });
            qc.invalidateQueries({ queryKey: candidateKeys.all }); // a range's map decides where its hosts get added
        },
    });
}
