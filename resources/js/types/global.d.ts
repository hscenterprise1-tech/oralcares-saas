import { Config, RouteName, RouteParamsWithQueryOverload } from 'ziggy-js';

declare global {
    function route(): import('ziggy-js').Router;
    function route<T extends RouteName>(
        name: T,
        params?: RouteParamsWithQueryOverload<T> | undefined,
        absolute?: boolean,
        config?: Config
    ): string;
    function route(
        name: string,
        params?: any,
        absolute?: boolean,
        config?: Config
    ): string;
}
