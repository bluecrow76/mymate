import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '../../../lib/apiClient';
import { subnetKeys } from './getSubnets';
import { candidateKeys } from './getCandidates';

export function useDeleteSubnet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await apiClient.delete(`/subnets/${id}`);
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: subnetKeys.all });
            qc.invalidateQueries({ queryKey: candidateKeys.all }); // a range's map decides where its hosts get added
        },
    });
}
