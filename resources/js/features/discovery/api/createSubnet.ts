import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import type { Subnet, SubnetPlacement } from '../../../types';
import { subnetKeys } from './getSubnets';
import { candidateKeys } from './getCandidates';

export interface CreateSubnetInput {
    cidr: string;
    label?: string | null;
    scan_interval_s?: number;
    agent_id?: number | null;
    map_id?: number | null;
    placement?: SubnetPlacement;
}

export function useCreateSubnet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (input: CreateSubnetInput): Promise<Subnet> => {
            const { data } = await apiClient.post<{ data: Subnet }>('/subnets', input);
            return data.data;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: subnetKeys.all });
            qc.invalidateQueries({ queryKey: candidateKeys.all }); // a range's map decides where its hosts get added
        },
    });
}
