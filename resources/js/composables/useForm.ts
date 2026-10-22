import { reactive, ref } from 'vue';
import { toApiError, type ValidationErrors } from '@/api/client';
import { useToast } from '@/stores/toast';

/**
 * Submission state for a form: busy flag, Laravel validation errors and a
 * top-level message. `run()` wraps any request.
 */
export function useForm() {
    const busy = ref(false);
    const errors = reactive<ValidationErrors>({});
    const message = ref<string | null>(null);
    const toast = useToast();

    function clear() {
        Object.keys(errors).forEach((k) => delete errors[k]);
        message.value = null;
    }

    async function run<T>(fn: () => Promise<T>, success?: string): Promise<T | undefined> {
        busy.value = true;
        clear();
        try {
            const result = await fn();
            if (success) toast.success(success);
            return result;
        } catch (e) {
            const ae = toApiError(e);
            Object.assign(errors, ae.errors);
            message.value = ae.message;
            if (!Object.keys(ae.errors).length) toast.error(ae.message);
            return undefined;
        } finally {
            busy.value = false;
        }
    }

    return { busy, errors, message, run, clear };
}
